<?php
/**
 * Modération des avis : publier, masquer, supprimer.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/avis.php';
require_admin();

$urlPage = ADMIN_URL . '/avis';
$STATUTS = ['en_attente' => 'En attente', 'publie' => 'Publiés', 'masque' => 'Masqués'];
$filtre  = $_GET['statut'] ?? 'en_attente';
if ($filtre !== 'tous' && !isset($STATUTS[$filtre])) {
    $filtre = 'en_attente';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $id     = (int) ($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? '';
    if ($id && in_array($action, ['publier', 'masquer'], true)) {
        db()->prepare('UPDATE avis SET statut = ? WHERE id = ?')->execute([$action === 'publier' ? 'publie' : 'masque', $id]);
        flash('succes', $action === 'publier' ? 'Avis publié sur le site.' : 'Avis masqué.');
    } elseif ($id && $action === 'supprimer') {
        db()->prepare('DELETE FROM avis WHERE id = ?')->execute([$id]);
        flash('succes', 'Avis supprimé.');
    }
    $retour = $_POST['filtre'] ?? 'en_attente';
    redirect($urlPage . '?statut=' . urlencode($filtre === 'tous' || isset($STATUTS[$retour]) || $retour === 'tous' ? $retour : 'en_attente'));
}

$compte = ['en_attente' => 0, 'publie' => 0, 'masque' => 0];
$avis   = [];
$migre  = true;
try {
    foreach (db()->query('SELECT statut, COUNT(*) n FROM avis GROUP BY statut')->fetchAll() as $c) {
        $compte[$c['statut']] = (int) $c['n'];
    }
    $sql = "SELECT a.*, u.prenom, u.nom, u.email, COALESCE(r.libelle, p.nom) AS soin, r.debut
            FROM avis a
            JOIN reservations r ON r.id = a.reservation_id
            JOIN prestations p  ON p.id = r.prestation_id
            JOIN utilisateurs u ON u.id = a.client_id"
         . ($filtre === 'tous' ? '' : ' WHERE a.statut = ' . db()->quote($filtre)) . ' ORDER BY a.created_at DESC LIMIT 200';
    $avis = db()->query($sql)->fetchAll();
} catch (PDOException $e) {
    $migre = false;
}
$resume = avis_resume();

$pageTitle   = 'Avis';
$activeAdmin = 'messages';
require __DIR__ . '/../includes/admin_header.php';
?>

<section class="entete-page entete-messages">
    <div>
        <h1>Avis des clientes</h1>
        <p class="discret">
            <?= $resume['nombre'] > 0
                ? 'Note moyenne affichée sur le site : ' . str_replace('.', ',', (string) $resume['moyenne']) . ' / 5 (' . $resume['nombre'] . ' avis publié' . ($resume['nombre'] > 1 ? 's' : '') . ').'
                : 'Aucun avis publié pour l\'instant.' ?>
            Les clientes sont invitées à noter quand tu marques un rendez-vous « Terminé ».
        </p>
    </div>
</section>

<?= onglets_messages_avis('avis') ?>

<?php if (!$migre): ?>
    <p class="alerte alerte-erreur">La table des avis n'existe pas encore : lance la migration <code>sql/etape25_veille_avis.sql</code>.</p>
<?php else: ?>
<nav class="filtres" aria-label="Filtrer par statut">
    <?php foreach ($STATUTS + ['tous' => 'Tous'] as $cle => $lib): ?>
        <a href="<?= $urlPage ?>?statut=<?= $cle ?>" class="pastille<?= $filtre === $cle ? ' pastille-active' : '' ?>"><?= e($lib) ?><?= $cle !== 'tous' ? ' (' . $compte[$cle] . ')' : '' ?></a>
    <?php endforeach; ?>
</nav>

<?php if (!$avis): ?>
    <p class="discret">Aucun avis dans cette catégorie.</p>
<?php else: ?>
    <div class="liste-messages">
        <?php foreach ($avis as $a): ?>
            <article class="message-carte<?= $a['statut'] === 'en_attente' ? ' message-non-lu' : '' ?>">
                <header class="message-entete">
                    <div>
                        <?= etoiles_html((int) $a['note']) ?>
                        <strong><?= e(nom_public($a['prenom'], $a['nom'])) ?></strong>
                        <span class="badge badge-<?= ['en_attente' => 'attente', 'publie' => 'ok', 'masque' => 'neutre'][$a['statut']] ?>"><?= e($STATUTS[$a['statut']] === 'Publiés' ? 'Publié' : ($STATUTS[$a['statut']] === 'Masqués' ? 'Masqué' : 'En attente')) ?></span>
                        <br>
                        <span class="discret petit-note"><?= e($a['soin']) ?> · rendez-vous du <?= e(format_date_fr(new DateTimeImmutable($a['debut']))) ?> · <?= e($a['email']) ?></span>
                    </div>
                </header>
                <?php if ($a['commentaire']): ?>
                    <p class="message-texte"><?= nl2br(e($a['commentaire'])) ?></p>
                <?php else: ?>
                    <p class="discret petit-note">Pas de commentaire, seulement la note.</p>
                <?php endif; ?>
                <div class="message-actions">
                    <?php foreach (['publier' => ['Publier', 'publie'], 'masquer' => ['Masquer', 'masque']] as $action => [$lib, $cible]): if ($a['statut'] === $cible) { continue; } ?>
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="<?= $action ?>">
                            <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                            <input type="hidden" name="filtre" value="<?= e($filtre) ?>">
                            <button type="submit" class="btn-action"><?= $lib ?></button>
                        </form>
                    <?php endforeach; ?>
                    <form method="post" data-confirmer="Supprimer définitivement cet avis ?">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="supprimer">
                        <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                        <input type="hidden" name="filtre" value="<?= e($filtre) ?>">
                        <button type="submit" class="btn-action btn-action-danger">Supprimer</button>
                    </form>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; endif; ?>

<?php require __DIR__ . '/../includes/admin_footer.php';
