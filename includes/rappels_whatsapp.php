<?php
/**
 * Rappels WhatsApp semi-automatiques.
 *
 * Environ 1 h avant un rendez-vous confirmé, le tableau de bord de l'admin le propose dans un bloc
 * « Rappels WhatsApp ». Un clic ouvre WhatsApp avec le message déjà écrit pour la cliente ; il reste à
 * appuyer sur « Envoyer » (WhatsApp n'autorise pas l'envoi sans action humaine hors API officielle).
 * Le rendez-vous est alors noté comme rappelé (colonne rappel_whatsapp_le, étape 24).
 */
require_once __DIR__ . '/reservation.php';
require_once __DIR__ . '/contact.php';

const WHATSAPP_RAPPEL_FENETRE_MIN = 90;   // un rendez-vous apparaît dans le bloc 90 minutes avant son début

/** Message pré-rempli envoyé à la cliente. */
function message_rappel_whatsapp(array $r): string
{
    $debut  = new DateTimeImmutable($r['debut']);
    $reste  = max(0, (int) $r['prix_fcfa'] - (int) $r['paye']);
    $texte  = 'Bonjour ' . $r['prenom'] . ', ici ' . APP_NAME . '. Petit rappel : votre rendez-vous (' . $r['soin']
            . ') est aujourd\'hui à ' . $debut->format('H\hi') . '.';
    if ($reste > 0) {
        $texte .= ' Reste à régler sur place : ' . format_fcfa($reste) . '.';
    }
    if (defined('SALON_ADRESSE') && SALON_ADRESSE !== '') {
        $texte .= ' Adresse : ' . SALON_ADRESSE . '.';
    }
    return $texte . ' À tout à l\'heure ! En cas d\'empêchement, répondez-nous ici.';
}

/** Rendez-vous confirmés qui commencent dans la fenêtre, avec l'état du rappel. Table non migrée : tableau vide. */
function rappels_whatsapp_du_moment(): array
{
    try {
        $stmt = db()->prepare(
            sql_reservations_admin() . "
             WHERE r.statut = 'confirmee' AND r.debut > NOW() AND r.debut <= NOW() + INTERVAL " . WHATSAPP_RAPPEL_FENETRE_MIN . " MINUTE
             ORDER BY r.debut"
        );
        $stmt->execute();
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('[Rappels WhatsApp] ' . $e->getMessage());
        return [];
    }
}

/** Nombre de rappels encore à envoyer (pastille du menu). */
function compter_rappels_whatsapp_a_envoyer(): int
{
    try {
        $telephones = db()->query(
            "SELECT u.telephone FROM reservations r JOIN utilisateurs u ON u.id = r.client_id
             WHERE r.statut = 'confirmee' AND r.rappel_whatsapp_le IS NULL
               AND r.debut > NOW() AND r.debut <= NOW() + INTERVAL " . WHATSAPP_RAPPEL_FENETRE_MIN . " MINUTE"
        )->fetchAll(PDO::FETCH_COLUMN);
        // Seuls comptent les rendez-vous qu'on peut vraiment rappeler (numéro valable)
        return count(array_filter($telephones, fn ($t) => numero_international((string) $t) !== ''));
    } catch (PDOException $e) {
        return 0;   // migration de l'étape 24 pas encore faite
    }
}

/** Le bloc du tableau de bord (toujours présent dans la page, vide s'il n'y a rien : il est rafraîchi tout seul). */
function afficher_rappels_whatsapp(array $lignes, string $retour): void
{
    $aEnvoyer = count(array_filter($lignes, fn ($r) => empty($r['rappel_whatsapp_le']) && numero_international((string) $r['telephone']) !== ''));
    echo '<section class="bloc-compte" id="rappels-whatsapp"' . ($lignes ? '' : ' hidden') . '>';
    if ($lignes) { ?>
        <h2>Rappels WhatsApp <?php if ($aEnvoyer > 0): ?><span class="compteur-nav" aria-label="<?= $aEnvoyer ?> à envoyer"><?= $aEnvoyer ?></span><?php endif; ?></h2>
        <p class="discret">Ces rendez-vous commencent dans moins d'1 h 30. Un clic ouvre WhatsApp avec le message déjà écrit : il reste à appuyer sur Envoyer.</p>
        <ul class="rappels-liste">
        <?php foreach ($lignes as $r):
            $debut = new DateTimeImmutable($r['debut']);
            $minutes = max(1, (int) ceil(($debut->getTimestamp() - time()) / 60));
            $fait = !empty($r['rappel_whatsapp_le']);
            $valide = numero_international((string) $r['telephone']) !== ''; ?>
            <li class="rappel<?= $fait ? ' fait' : '' ?>">
                <div class="rappel-infos">
                    <strong><?= $debut->format('H\hi') ?></strong> · <?= e($r['prenom'] . ' ' . $r['nom_client']) ?>
                    <span class="discret">· <?= e($r['soin']) ?> · dans <?= $minutes ?> min</span>
                    <?php if ($fait): ?><br><span class="paiement-ok">✓ Rappel ouvert à <?= (new DateTimeImmutable($r['rappel_whatsapp_le']))->format('H\hi') ?></span><?php endif; ?>
                </div>
                <?php if ($valide): ?>
                    <form method="post" target="_blank" class="form-rappel">
                        <?= csrf_field() ?>
                        <input type="hidden" name="rappel_whatsapp" value="1">
                        <input type="hidden" name="reservation" value="<?= (int) $r['id'] ?>">
                        <input type="hidden" name="retour" value="<?= e($retour) ?>">
                        <button type="submit" class="btn<?= $fait ? '' : ' btn-plein' ?> btn-petit"><?= $fait ? 'Renvoyer' : 'Envoyer sur WhatsApp' ?></button>
                    </form>
                <?php else: ?>
                    <span class="discret petit-note">Numéro invalide : <a href="tel:<?= e($r['telephone']) ?>"><?= e($r['telephone']) ?></a></span>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
        </ul>
    <?php }
    echo '</section>';
}

/** POST du bouton : note le rappel puis envoie l'admin vers WhatsApp (le formulaire s'ouvre dans un nouvel onglet). */
function traiter_rappel_whatsapp(): void
{
    csrf_verify();
    $retour = $_POST['retour'] ?? '';
    if (!is_string($retour) || !str_starts_with($retour, '/') || str_starts_with($retour, '//')) {
        $retour = ADMIN_URL . '/';
    }

    $stmt = db()->prepare(sql_reservations_admin() . " WHERE r.id = ? AND r.statut = 'confirmee' AND r.debut > NOW()");
    $stmt->execute([(int) ($_POST['reservation'] ?? 0)]);
    $r = $stmt->fetch();
    if (!$r) {
        flash('erreur', 'Ce rendez-vous n\'est plus à venir ou n\'est plus confirmé.');
        redirect($retour);
    }

    $lien = lien_whatsapp(message_rappel_whatsapp($r), (string) $r['telephone']);
    if ($lien === '') {
        flash('erreur', 'Le numéro de ' . $r['prenom'] . ' n\'est pas valide : appelle-la plutôt.');
        redirect($retour);
    }

    try {
        db()->prepare('UPDATE reservations SET rappel_whatsapp_le = NOW() WHERE id = ?')->execute([$r['id']]);
    } catch (PDOException $e) {
        error_log('[Rappels WhatsApp] ' . $e->getMessage());   // étape 24 pas migrée : on ouvre quand même WhatsApp
    }
    redirect($lien);
}
