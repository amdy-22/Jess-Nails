<?php
/**
 * Actions de l'admin sur une réservation (utilisées par le tableau de bord et la liste).
 * Formulaire POST avec : action_resa, reservation, retour (URL interne de retour).
 */
require_once __DIR__ . '/reservation.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/contact.php';

/** Actions possibles sur une réservation, selon son état. */
function actions_admin(array $r): array
{
    $debut       = new DateTimeImmutable($r['debut']);
    $finDuJour   = new DateTimeImmutable('tomorrow');
    $commence    = $debut < $finDuJour;                 // aujourd'hui ou passé
    $aVenir      = $debut > new DateTimeImmutable();
    $actions     = [];

    if ($r['statut'] === 'confirmee' && $commence) {
        $actions['terminee'] = 'Terminé';
        $actions['absente']  = 'Absente';
    }
    if (in_array($r['statut'], ['terminee', 'absente'], true)) {
        $actions['confirmee'] = 'Remettre à confirmé';
    }
    if ($r['statut'] === 'confirmee' && new DateTimeImmutable($r['fin']) > new DateTimeImmutable()) {
        $actions['deplacer'] = 'Déplacer';
    }
    if ($aVenir && ($r['statut'] === 'confirmee' || ($r['statut'] === 'en_attente_paiement' && $r['bloquee']))) {
        $actions['annuler'] = 'Annuler';
    }
    if ($r['statut'] === 'annulee' && (int) $r['paye'] > 0) {
        $actions['rembourse'] = 'Acompte remboursé';
    }
    return $actions;
}

/** Traite le POST d'une action admin, puis redirige. */
function traiter_action_admin(): void
{
    csrf_verify();
    $retour = $_POST['retour'] ?? '';
    if (!is_string($retour) || !str_starts_with($retour, '/') || str_starts_with($retour, '//')) {
        $retour = ADMIN_URL . '/reservations.php';
    }

    $stmt = db()->prepare(
        'SELECT r.*, (' . sql_reservation_bloquante() . ') AS bloquee,
                (SELECT COALESCE(SUM(pa.montant_fcfa), 0) FROM paiements pa
                 WHERE pa.reservation_id = r.id AND pa.statut = \'approuve\') AS paye
         FROM reservations r WHERE r.id = ?'
    );
    $stmt->execute([(int) ($_POST['reservation'] ?? 0)]);
    $r = $stmt->fetch();
    $action = $_POST['action_resa'] ?? '';

    if (!$r || !array_key_exists($action, actions_admin($r))) {
        flash('erreur', 'Cette action n\'est pas possible sur cette réservation.');
        redirect($retour);
    }

    switch ($action) {
        case 'terminee':
        case 'absente':
        case 'confirmee':
            db()->prepare('UPDATE reservations SET statut = ? WHERE id = ?')->execute([$action, $r['id']]);
            $messages = ['terminee' => 'Rendez-vous marqué terminé.', 'absente' => 'Cliente marquée absente.', 'confirmee' => 'Rendez-vous remis à confirmé.'];
            flash('succes', $messages[$action]);
            break;

        case 'annuler':
            db()->prepare("UPDATE reservations SET statut = 'annulee' WHERE id = ?")->execute([$r['id']]);
            try {
                notifier_annulation_par_institut((int) $r['id']);
            } catch (Throwable $e) {
                error_log('[Emails] ' . $e->getMessage());
            }
            flash('succes', 'Réservation annulée, la cliente est prévenue par email.'
                . ((int) $r['paye'] > 0 ? ' Pense à rembourser l\'acompte de ' . format_fcfa((int) $r['paye']) . '.' : ''));
            break;

        case 'rembourse':
            db()->prepare("UPDATE paiements SET statut = 'rembourse' WHERE reservation_id = ? AND statut = 'approuve'")
                ->execute([$r['id']]);
            flash('succes', 'Acompte noté comme remboursé.');
            break;
    }
    redirect($retour);
}

/** Petit formulaire d'actions pour une ligne de réservation. */
function boutons_actions_admin(array $r, string $retour): string
{
    $html = '';
    foreach (actions_admin($r) as $action => $libelle) {
        if ($action === 'deplacer') {   // simple lien vers la page de déplacement
            $html .= '<a class="btn-action" href="' . ADMIN_URL . '/deplacer.php?reservation=' . (int) $r['id'] . '">Déplacer</a>';
            continue;
        }
        $confirmer = in_array($action, ['annuler', 'absente', 'rembourse'], true)
            ? ' data-confirmer="' . e($libelle . ' : ' . $r['prenom'] . ' ' . $r['nom_client'] . ', ' . format_date_fr(new DateTimeImmutable($r['debut']), true) . ' ?') . '"'
            : '';
        $classe = $action === 'annuler' ? 'btn-action btn-action-danger' : 'btn-action';
        $html .= '<form method="post"' . $confirmer . '>'
            . csrf_field()
            . '<input type="hidden" name="action_resa" value="' . e($action) . '">'
            . '<input type="hidden" name="reservation" value="' . (int) $r['id'] . '">'
            . '<input type="hidden" name="retour" value="' . e($retour) . '">'
            . '<button type="submit" class="' . $classe . '">' . e($libelle) . '</button>'
            . '</form>';
    }
    return $html;
}

/** Badge de statut vu par l'admin (même logique que côté cliente). */
function badge_admin(array $r): string
{
    [$libelle, $ton] = statut_affiche($r, (bool) $r['bloquee']);
    if ($r['statut'] === 'confirmee' && new DateTimeImmutable($r['debut']) < new DateTimeImmutable()) {
        [$libelle, $ton] = ['À clôturer', 'attente'];   // passé mais pas encore marqué terminé/absente
    }
    return '<span class="badge badge-' . e($ton) . '">' . e($libelle) . '</span>';
}

/** Requête SQL commune : réservation + soin + cliente + praticienne + paiement + absences. */
function sql_reservations_admin(): string
{
    return 'SELECT r.*, COALESCE(r.libelle, p.nom) AS soin, TIMESTAMPDIFF(MINUTE, r.debut, r.fin) AS duree_minutes, pr.nom AS praticienne,
                   u.prenom, u.nom AS nom_client, u.telephone, u.email,
                   (' . sql_reservation_bloquante() . ') AS bloquee,
                   (SELECT COALESCE(SUM(pa.montant_fcfa), 0) FROM paiements pa
                    WHERE pa.reservation_id = r.id AND pa.statut = \'approuve\') AS paye,
                   (SELECT COALESCE(SUM(pa.montant_fcfa), 0) FROM paiements pa
                    WHERE pa.reservation_id = r.id AND pa.statut = \'rembourse\') AS rembourse,
                   (SELECT COUNT(*) FROM reservations r2
                    WHERE r2.client_id = r.client_id AND r2.statut = \'absente\') AS nb_absences
            FROM reservations r
            JOIN prestations p   ON p.id  = r.prestation_id
            JOIN praticiennes pr ON pr.id = r.praticienne_id
            JOIN utilisateurs u  ON u.id  = r.client_id';
}

/** Texte "payé / sur place / remboursé" pour une ligne. */
/** Petite étiquette indiquant l'origine d'un rendez-vous saisi par l'institut. */
function tag_source(array $r): string
{
    $libelles = ['telephone' => 'Téléphone', 'whatsapp' => 'WhatsApp', 'sur_place' => 'Sur place'];
    $source = $r['source'] ?? 'en_ligne';
    return isset($libelles[$source]) ? ' <span class="tag-source">' . $libelles[$source] . '</span>' : '';
}

function etat_paiement(array $r): string
{
    if ((int) $r['paye'] > 0) {
        return '<span class="paiement-ok">Acompte ' . format_fcfa((int) $r['paye']) . '</span>';
    }
    if ((int) $r['rembourse'] > 0) {
        return '<span class="discret">Remboursé</span>';
    }
    return '<span class="discret">Sur place</span>';
}

/** Tableau de réservations (tableau de bord et page Réservations). */
function afficher_tableau(array $lignes, string $retour, bool $avecDate): void
{ ?>
    <div class="tableau-conteneur">
        <table class="tableau">
            <thead>
                <tr>
                    <th><?= $avecDate ? 'Date' : 'Heure' ?></th>
                    <th>Cliente</th>
                    <th>Soin</th>
                    <th>Praticienne</th>
                    <th class="col-num">Prix</th>
                    <th>Règlement</th>
                    <th>Statut</th>
                    <th><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($lignes as $r):
                $debut = new DateTimeImmutable($r['debut']); ?>
                <tr>
                    <td class="nowrap">
                        <?php if ($avecDate): ?><?= $debut->format('d/m') ?> · <?php endif; ?>
                        <strong><?= $debut->format('H\hi') ?></strong>
                    </td>
                    <td>
                        <?= e($r['prenom'] . ' ' . $r['nom_client']) ?>
                        <?php if ($r['nb_absences'] > 0): ?>
                            <span class="badge badge-annule" title="Absences passées"><?= (int) $r['nb_absences'] ?> abs.</span>
                        <?php endif; ?>
                        <br><a class="discret" href="tel:<?= e($r['telephone']) ?>"><?= e($r['telephone']) ?></a>
                        <?php $wa = lien_whatsapp(
                            'Bonjour ' . $r['prenom'] . ', ici ' . APP_NAME . ' au sujet de votre rendez-vous du '
                            . format_date_fr($debut, true) . ' (' . $r['soin'] . ').',
                            (string) $r['telephone']
                        ); ?>
                        <?php if ($wa): ?> · <a class="lien-whatsapp" href="<?= e($wa) ?>" target="_blank" rel="noopener">WhatsApp</a><?php endif; ?>
                    </td>
                    <td><?= e($r['soin']) ?><?= tag_source($r) ?><?php if ($r['note_client']): ?><br><span class="discret petit-note">« <?= e($r['note_client']) ?> »</span><?php endif; ?></td>
                    <td><?= e($r['praticienne']) ?></td>
                    <td class="col-num"><?= format_fcfa((int) $r['prix_fcfa']) ?></td>
                    <td><?= etat_paiement($r) ?></td>
                    <td><?= badge_admin($r) ?></td>
                    <td><div class="actions-ligne"><?= boutons_actions_admin($r, $retour) ?></div></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php
}
