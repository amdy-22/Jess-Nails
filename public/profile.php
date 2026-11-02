<?php
/**
 * Espace client : rendez-vous à venir, historique, annulation, profil.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/reservation.php';
require_once __DIR__ . '/../includes/notifications.php';
require_login();

$client   = current_user();
$urlPage  = BASE_URL . '/profile.php';

// =====================================================
// Traitement des formulaires
// =====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    // ---------- Annuler un rendez-vous ----------
    if ($action === 'annuler') {
        $stmt = db()->prepare(
            'SELECT r.*, (' . sql_reservation_bloquante() . ') AS bloquee
             FROM reservations r WHERE r.id = ? AND r.client_id = ?'
        );
        $stmt->execute([(int) ($_POST['reservation'] ?? 0), $client['id']]);
        $resa = $stmt->fetch();

        if (!$resa || !annulation_possible($resa, (bool) $resa['bloquee'])) {
            flash('erreur', 'Ce rendez-vous ne peut plus être annulé en ligne (moins de '
                . DELAI_ANNULATION_H . ' h avant). Contacte directement l\'institut.');
            redirect($urlPage);
        }

        $etaitConfirmee = $resa['statut'] === 'confirmee';
        $paye = montant_paye((int) $resa['id']);
        $maj = db()->prepare(
            "UPDATE reservations SET statut = 'annulee'
             WHERE id = ? AND statut IN ('confirmee', 'en_attente_paiement')"
        );
        $maj->execute([$resa['id']]);

        if ($maj->rowCount() > 0) {
            try {
                notifier_annulation((int) $resa['id'], $etaitConfirmee);
            } catch (Throwable $e) {
                error_log('[Emails] ' . $e->getMessage());
            }
            flash('succes', $paye > 0
                ? 'Rendez-vous annulé. L\'institut est prévenu pour le remboursement de ton acompte.'
                : 'Rendez-vous annulé.');
        }
        redirect($urlPage);
    }

    // ---------- Modifier le profil ----------
    if ($action === 'profil') {
        $prenom = trim($_POST['prenom'] ?? '');
        $nom    = trim($_POST['nom'] ?? '');
        $tel    = preg_replace('/^(\+229|00229)/', '', preg_replace('/[\s.\-]/', '', $_POST['telephone'] ?? ''));

        if ($prenom === '' || $nom === '') {
            flash('erreur', 'Le prénom et le nom sont obligatoires.');
        } elseif (!preg_match('/^01\d{8}$/', $tel)) {
            flash('erreur', 'Le numéro doit compter 10 chiffres et commencer par 01.');
        } else {
            db()->prepare('UPDATE utilisateurs SET prenom = ?, nom = ?, telephone = ? WHERE id = ?')
                ->execute([$prenom, $nom, $tel, $client['id']]);
            flash('succes', 'Tes informations sont à jour.');
        }
        redirect($urlPage . '#profil');
    }

    // ---------- Changer le mot de passe ----------
    if ($action === 'mot_de_passe') {
        $actuel  = $_POST['actuel'] ?? '';
        $nouveau = $_POST['nouveau'] ?? '';
        $confirm = $_POST['confirmation'] ?? '';

        $stmt = db()->prepare('SELECT mot_de_passe FROM utilisateurs WHERE id = ?');
        $stmt->execute([$client['id']]);

        if (!password_verify($actuel, (string) $stmt->fetchColumn())) {
            flash('erreur', 'Le mot de passe actuel est incorrect.');
        } elseif (strlen($nouveau) < 8) {
            flash('erreur', 'Le nouveau mot de passe doit faire au moins 8 caractères.');
        } elseif ($nouveau !== $confirm) {
            flash('erreur', 'Les deux nouveaux mots de passe ne correspondent pas.');
        } else {
            db()->prepare('UPDATE utilisateurs SET mot_de_passe = ? WHERE id = ?')
                ->execute([password_hash($nouveau, PASSWORD_DEFAULT), $client['id']]);
            session_regenerate_id(true);
            flash('succes', 'Mot de passe modifié.');
        }
        redirect($urlPage . '#profil');
    }

    redirect($urlPage);
}

// =====================================================
// Données à afficher
// =====================================================
$stmt = db()->prepare(
    'SELECT r.*, COALESCE(r.libelle, p.nom) AS soin, TIMESTAMPDIFF(MINUTE, r.debut, r.fin) AS duree_minutes, pr.nom AS praticienne,
            (' . sql_reservation_bloquante() . ') AS bloquee,
            (SELECT COALESCE(SUM(pa.montant_fcfa), 0) FROM paiements pa
             WHERE pa.reservation_id = r.id AND pa.statut = \'approuve\') AS paye
     FROM reservations r
     JOIN prestations p   ON p.id  = r.prestation_id
     JOIN praticiennes pr ON pr.id = r.praticienne_id
     WHERE r.client_id = ?
     ORDER BY r.debut DESC'
);
$stmt->execute([$client['id']]);

$maintenant = new DateTimeImmutable();
$aVenir = $historique = [];
foreach ($stmt->fetchAll() as $r) {
    $actif = $r['statut'] === 'confirmee' || ($r['statut'] === 'en_attente_paiement' && $r['bloquee']);
    if ($actif && new DateTimeImmutable($r['fin']) > $maintenant) {
        $aVenir[] = $r;
    } else {
        $historique[] = $r;
    }
}
$aVenir = array_reverse($aVenir);   // le plus proche en premier

/** Affiche une carte de rendez-vous. */
function carte_rdv(array $r, bool $avecActions): void
{
    $debut = new DateTimeImmutable($r['debut']);
    $fin   = new DateTimeImmutable($r['fin']);
    [$libelle, $ton] = statut_affiche($r, (bool) $r['bloquee']);
    ?>
    <article class="rdv rdv-<?= e($ton) ?>">
        <div class="rdv-date">
            <span class="rdv-jour"><?= $debut->format('d') ?></span>
            <span class="rdv-mois"><?= e(mois_court($debut)) ?></span>
        </div>
        <div class="rdv-corps">
            <p class="rdv-titre"><?= e($r['soin']) ?></p>
            <p class="discret">
                <?= e(ucfirst(format_date_fr($debut))) ?>, <?= $debut->format('H\hi') ?> à <?= $fin->format('H\hi') ?>
                · <?= e($r['praticienne']) ?>
            </p>
            <p class="discret"><?= format_fcfa((int) $r['prix_fcfa']) ?>
                <?php if ($r['paye'] > 0): ?>
                    · acompte payé, reste <?= format_fcfa(max(0, (int) $r['prix_fcfa'] - (int) $r['paye'])) ?> sur place
                <?php elseif ($r['statut'] === 'confirmee'): ?>
                    · à régler sur place
                <?php endif; ?>
            </p>
        </div>
        <div class="rdv-actions">
            <span class="badge badge-<?= e($ton) ?>"><?= e($libelle) ?></span>

            <?php if ($avecActions && $r['statut'] === 'en_attente_paiement'): ?>
                <a class="btn btn-petit" href="<?= BASE_URL ?>/paiement/initier.php?reservation=<?= (int) $r['id'] ?>">Confirmer</a>
            <?php elseif ($avecActions && $r['statut'] === 'confirmee' && (int) $r['paye'] === 0): ?>
                <a class="lien-discret" href="<?= BASE_URL ?>/paiement/initier.php?reservation=<?= (int) $r['id'] ?>">Payer l'acompte en ligne</a>
            <?php endif; ?>

            <?php if ($avecActions && deplacement_possible($r)): ?>
                <a class="lien-discret" href="<?= BASE_URL ?>/deplacer.php?reservation=<?= (int) $r['id'] ?>">Déplacer</a>
            <?php endif; ?>

            <?php if ($avecActions && annulation_possible($r, (bool) $r['bloquee'])): ?>
                <form method="post" data-confirmer="Annuler ce rendez-vous du <?= e(format_date_fr($debut, true)) ?> ?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="annuler">
                    <input type="hidden" name="reservation" value="<?= (int) $r['id'] ?>">
                    <button type="submit" class="lien-bouton lien-danger">Annuler</button>
                </form>
            <?php elseif ($avecActions && $r['statut'] === 'confirmee'): ?>
                <span class="petit discret">Moins de <?= DELAI_ANNULATION_H ?> h : appelle l'institut pour annuler</span>
            <?php endif; ?>
        </div>
    </article>
    <?php
}

$pageTitle  = 'Mon compte';
$activePage = 'compte';
require __DIR__ . '/../includes/header.php';
?>

<section class="entete-page entete-compte">
    <div>
        <h1>Bonjour <?= e($client['prenom']) ?></h1>
        <p class="discret">Retrouve ici tes rendez-vous et tes informations.</p>
    </div>
    <a href="<?= BASE_URL ?>/reserver.php" class="btn btn-plein">Nouvelle réservation</a>
</section>

<section class="bloc-compte">
    <h2>Mes rendez-vous à venir</h2>
    <?php if (!$aVenir): ?>
        <p class="vide">Aucun rendez-vous prévu. <a href="<?= BASE_URL ?>/prestations.php">Découvrir nos soins</a></p>
    <?php else: ?>
        <div class="liste-rdv">
            <?php foreach ($aVenir as $r) { carte_rdv($r, true); } ?>
        </div>
    <?php endif; ?>
</section>

<?php if ($historique): ?>
<section class="bloc-compte">
    <h2>Historique</h2>
    <div class="liste-rdv">
        <?php foreach ($historique as $r) { carte_rdv($r, false); } ?>
    </div>
</section>
<?php endif; ?>

<section class="bloc-compte" id="profil">
    <h2>Mes informations</h2>
    <div class="grille-profil">
        <form method="post" class="resa-bloc">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="profil">
            <div class="ligne-2">
                <div class="champ">
                    <label for="prenom">Prénom</label>
                    <input type="text" id="prenom" name="prenom" value="<?= e($client['prenom']) ?>" required>
                </div>
                <div class="champ">
                    <label for="nom">Nom</label>
                    <input type="text" id="nom" name="nom" value="<?= e($client['nom']) ?>" required>
                </div>
            </div>
            <div class="champ">
                <label for="telephone">Téléphone</label>
                <input type="tel" id="telephone" name="telephone" value="<?= e($client['telephone']) ?>" required>
            </div>
            <div class="champ">
                <label for="email">Email</label>
                <input type="email" id="email" value="<?= e($client['email']) ?>" disabled>
            </div>
            <button type="submit" class="btn">Enregistrer</button>
        </form>

        <form method="post" class="resa-bloc">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="mot_de_passe">
            <div class="champ">
                <label for="actuel">Mot de passe actuel</label>
                <input type="password" id="actuel" name="actuel" autocomplete="current-password" required>
            </div>
            <div class="champ">
                <label for="nouveau">Nouveau mot de passe</label>
                <input type="password" id="nouveau" name="nouveau" minlength="8" autocomplete="new-password" required>
            </div>
            <div class="champ">
                <label for="confirmation">Confirmation</label>
                <input type="password" id="confirmation" name="confirmation" minlength="8" autocomplete="new-password" required>
            </div>
            <button type="submit" class="btn">Changer le mot de passe</button>
        </form>
    </div>
</section>

<?php require __DIR__ . '/../includes/footer.php'; ?>