<?php
/**
 * Gestion des praticiennes. Chaque praticienne active ajoute une "place" par créneau.
 */
require_once __DIR__ . '/../includes/auth.php';
require_admin();

$urlPage = ADMIN_URL . '/praticiennes.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    $id     = (int) ($_POST['id'] ?? 0);

    if ($action === 'ajouter') {
        $nom = trim($_POST['nom'] ?? '');
        if ($nom === '') {
            flash('erreur', 'Indique le nom de la praticienne.');
        } else {
            db()->prepare('INSERT INTO praticiennes (nom) VALUES (?)')->execute([$nom]);
            flash('succes', $nom . ' est ajoutée : un créneau de plus est disponible à chaque horaire.');
        }
    }

    if ($action === 'renommer') {
        $nom = trim($_POST['nom'] ?? '');
        if ($nom !== '') {
            db()->prepare('UPDATE praticiennes SET nom = ? WHERE id = ?')->execute([$nom, $id]);
            flash('succes', 'Nom mis à jour.');
        }
    }

    if ($action === 'basculer') {
        $st = db()->prepare('SELECT actif FROM praticiennes WHERE id = ?');
        $st->execute([$id]);
        $actif = $st->fetchColumn();

        if ($actif !== false) {
            if ((int) $actif === 1) {
                $nbActives = (int) db()->query('SELECT COUNT(*) FROM praticiennes WHERE actif = 1')->fetchColumn();
                if ($nbActives <= 1) {
                    flash('erreur', 'Il faut au moins une praticienne active, sinon plus aucun créneau n\'est proposé.');
                    redirect($urlPage);
                }
            }
            db()->prepare('UPDATE praticiennes SET actif = 1 - actif WHERE id = ?')->execute([$id]);

            $aVenir = db()->prepare("SELECT COUNT(*) FROM reservations WHERE praticienne_id = ? AND statut = 'confirmee' AND debut > NOW()");
            $aVenir->execute([$id]);
            $nb = (int) $aVenir->fetchColumn();
            flash('succes', (int) $actif === 1
                ? 'Praticienne désactivée : plus de nouvelles réservations avec elle.'
                    . ($nb ? ' Attention, ' . $nb . ' rendez-vous à venir lui restent attribués.' : '')
                : 'Praticienne réactivée.');
        }
    }

    if ($action === 'supprimer') {
        $nb = db()->prepare('SELECT COUNT(*) FROM reservations WHERE praticienne_id = ?');
        $nb->execute([$id]);
        if ((int) $nb->fetchColumn() > 0) {
            flash('erreur', 'Cette praticienne a un historique de rendez-vous : désactive-la plutôt que de la supprimer.');
        } else {
            db()->prepare('DELETE FROM praticiennes WHERE id = ?')->execute([$id]);
            flash('succes', 'Praticienne supprimée.');
        }
    }

    redirect($urlPage);
}

$praticiennes = db()->query(
    "SELECT pr.*,
            (SELECT COUNT(*) FROM reservations r WHERE r.praticienne_id = pr.id AND r.statut = 'confirmee' AND r.debut > NOW()) AS a_venir,
            (SELECT COUNT(*) FROM reservations r WHERE r.praticienne_id = pr.id AND DATE(r.debut) = CURDATE() AND r.statut IN ('confirmee','terminee')) AS aujourdhui,
            (SELECT COUNT(*) FROM reservations r WHERE r.praticienne_id = pr.id) AS total
     FROM praticiennes pr ORDER BY pr.actif DESC, pr.nom"
)->fetchAll();
$nbActives = count(array_filter($praticiennes, fn($p) => $p['actif']));

$pageTitle   = 'Praticiennes';
$activeAdmin = 'praticiennes';
require __DIR__ . '/../includes/admin_header.php';
?>

<section class="entete-page">
    <h1>Praticiennes</h1>
    <p class="discret">
        <?= $nbActives ?> praticienne<?= $nbActives > 1 ? 's' : '' ?> active<?= $nbActives > 1 ? 's' : '' ?> :
        jusqu'à <?= $nbActives ?> cliente<?= $nbActives > 1 ? 's' : '' ?> peuvent réserver le même horaire.
    </p>
</section>

<div class="grille-profil grille-admin">
    <div class="tableau-conteneur">
        <table class="tableau">
            <thead>
                <tr><th>Nom</th><th>Aujourd'hui</th><th>À venir</th><th>Statut</th><th><span class="sr-only">Actions</span></th></tr>
            </thead>
            <tbody>
            <?php foreach ($praticiennes as $p): ?>
                <tr class="<?= $p['actif'] ? '' : 'ligne-inactive' ?>">
                    <td>
                        <form method="post" class="form-renommer">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="renommer">
                            <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                            <label class="sr-only" for="p<?= (int) $p['id'] ?>">Nom</label>
                            <input type="text" id="p<?= (int) $p['id'] ?>" name="nom" value="<?= e($p['nom']) ?>">
                            <button type="submit" class="btn-action">Renommer</button>
                        </form>
                    </td>
                    <td class="nowrap"><?= (int) $p['aujourdhui'] ?> rdv</td>
                    <td class="nowrap">
                        <a href="<?= ADMIN_URL ?>/reservations.php?praticienne=<?= (int) $p['id'] ?>&amp;statut=confirmee"><?= (int) $p['a_venir'] ?> rdv</a>
                    </td>
                    <td><span class="badge badge-<?= $p['actif'] ? 'ok' : 'neutre' ?>"><?= $p['actif'] ? 'Active' : 'Inactive' ?></span></td>
                    <td>
                        <div class="actions-ligne">
                            <form method="post" <?= $p['actif'] && $p['a_venir'] ? 'data-confirmer="' . e($p['nom'] . ' a ' . $p['a_venir'] . ' rendez-vous à venir. Ils restent maintenus. La désactiver quand même ?') . '"' : '' ?>>
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="basculer">
                                <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                                <button type="submit" class="btn-action"><?= $p['actif'] ? 'Désactiver' : 'Réactiver' ?></button>
                            </form>
                            <?php if ((int) $p['total'] === 0): ?>
                                <form method="post" data-confirmer="Supprimer <?= e($p['nom']) ?> ?">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="supprimer">
                                    <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                                    <button type="submit" class="btn-action btn-action-danger">Supprimer</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <form method="post" class="resa-bloc">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="ajouter">
        <h2>Ajouter une praticienne</h2>
        <div class="champ">
            <label for="nom">Nom ou prénom</label>
            <input type="text" id="nom" name="nom" maxlength="120" placeholder="Ex. Aïcha" required>
        </div>
        <button type="submit" class="btn btn-plein">Ajouter</button>
        <p class="discret petit-note">Le nom apparaît dans le récapitulatif et les emails de la cliente.</p>
    </form>
</div>

<?php require __DIR__ . '/../includes/admin_footer.php'; ?>