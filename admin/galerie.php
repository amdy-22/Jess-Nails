<?php
/**
 * Galerie photos (admin) : envoi de plusieurs photos, légende, catégorie, ordre, masquer, supprimer.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/galerie.php';
require_admin();

$urlPage = ADMIN_URL . '/galerie';

// =====================================================
// Traitements
// =====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Envoi plus lourd que post_max_size : PHP vide $_POST, le jeton CSRF disparaît avec
    if (!$_POST && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        flash('erreur', 'Envoi trop lourd pour le serveur (limite : ' . ini_get('post_max_size') . ' au total). Envoie moins de photos à la fois.');
        redirect($urlPage);
    }
    csrf_verify();
    $action = $_POST['action'] ?? '';
    $id     = (int) ($_POST['id'] ?? 0);

    // ---------- Ajouter des photos ----------
    if ($action === 'ajouter') {
        $categorieId = (int) ($_POST['categorie'] ?? 0) ?: null;
        $legende     = mb_substr(trim($_POST['legende'] ?? ''), 0, 150);
        $fichiers    = array_filter(fichiers_envoyes($_FILES['photos'] ?? []), fn ($f) => ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE);

        if (!$fichiers) {
            flash('erreur', 'Choisis au moins une photo.');
            redirect($urlPage);
        }

        $ordre = (int) db()->query('SELECT COALESCE(MAX(ordre), 0) FROM photos')->fetchColumn();
        $stmt  = db()->prepare('INSERT INTO photos (fichier, largeur, hauteur, legende, categorie_id, ordre) VALUES (?, ?, ?, ?, ?, ?)');
        $ajoutees = 0;
        $erreurs  = [];
        foreach ($fichiers as $f) {
            try {
                $photo = traiter_photo($f);
                $stmt->execute([$photo['fichier'], $photo['largeur'], $photo['hauteur'], $legende !== '' ? $legende : null, $categorieId, ++$ordre]);
                $ajoutees++;
            } catch (RuntimeException $e) {
                $erreurs[] = $e->getMessage();
            }
        }

        if ($ajoutees) {
            flash('succes', $ajoutees . ' photo' . ($ajoutees > 1 ? 's ajoutées' : ' ajoutée') . ' à la galerie.');
        }
        if ($erreurs) {
            flash('erreur', implode(' ', $erreurs));
        }
        redirect($urlPage);
    }

    // ---------- Modifier légende et catégorie ----------
    if ($action === 'modifier' && $id) {
        $legende = mb_substr(trim($_POST['legende'] ?? ''), 0, 150);
        db()->prepare('UPDATE photos SET legende = ?, categorie_id = ? WHERE id = ?')
            ->execute([$legende !== '' ? $legende : null, (int) ($_POST['categorie'] ?? 0) ?: null, $id]);
        flash('succes', 'Photo mise à jour.');
        redirect($urlPage . '#photo-' . $id);
    }

    // ---------- Masquer / afficher ----------
    if ($action === 'visibilite' && $id) {
        db()->prepare('UPDATE photos SET visible = 1 - visible WHERE id = ?')->execute([$id]);
        redirect($urlPage . '#photo-' . $id);
    }

    // ---------- Monter / descendre (échange avec la voisine) ----------
    if (in_array($action, ['monter', 'descendre'], true) && $id) {
        $pdo = db();
        $pdo->beginTransaction();
        $cur = $pdo->prepare('SELECT id, ordre FROM photos WHERE id = ? FOR UPDATE');
        $cur->execute([$id]);
        if ($photo = $cur->fetch()) {
            // La liste est triée par ordre décroissant : "monter" = prendre la place de la voisine d'ordre supérieur
            $sql = $action === 'monter'
                ? 'SELECT id, ordre FROM photos WHERE ordre > ? ORDER BY ordre ASC LIMIT 1 FOR UPDATE'
                : 'SELECT id, ordre FROM photos WHERE ordre < ? ORDER BY ordre DESC LIMIT 1 FOR UPDATE';
            $v = $pdo->prepare($sql);
            $v->execute([$photo['ordre']]);
            if ($voisine = $v->fetch()) {
                $maj = $pdo->prepare('UPDATE photos SET ordre = ? WHERE id = ?');
                $maj->execute([$voisine['ordre'], $photo['id']]);
                $maj->execute([$photo['ordre'], $voisine['id']]);
            }
        }
        $pdo->commit();
        redirect($urlPage . '#photo-' . $id);
    }

    // ---------- Supprimer ----------
    if ($action === 'supprimer' && $id) {
        $st = db()->prepare('SELECT fichier FROM photos WHERE id = ?');
        $st->execute([$id]);
        if ($fichier = $st->fetchColumn()) {
            db()->prepare('DELETE FROM photos WHERE id = ?')->execute([$id]);
            supprimer_fichiers_photo($fichier);
            flash('succes', 'Photo supprimée.');
        }
        redirect($urlPage);
    }

    redirect($urlPage);
}

// =====================================================
// Affichage
// =====================================================
$categories = db()->query('SELECT id, nom FROM categories ORDER BY ordre, id')->fetchAll();
$photos = db()->query(
    'SELECT ph.*, c.nom AS categorie FROM photos ph LEFT JOIN categories c ON c.id = ph.categorie_id
     ORDER BY ph.ordre DESC, ph.id DESC'
)->fetchAll();
$nbVisibles = count(array_filter($photos, fn ($p) => (int) $p['visible'] === 1));
$limite = min(limite_envoi_serveur() ?: GALERIE_MAX_OCTETS, GALERIE_MAX_OCTETS);

$pageTitle   = 'Galerie photos';
$activeAdmin = 'galerie';
require __DIR__ . '/../includes/admin_header.php';

/** Liste déroulante des catégories (option vide = toutes les prestations). */
function select_categories(array $categories, ?int $choisie, string $id): string
{
    $html = '<select id="' . $id . '" name="categorie"><option value="0">Aucune (photo générale)</option>';
    foreach ($categories as $c) {
        $html .= '<option value="' . (int) $c['id'] . '"' . ((int) $c['id'] === $choisie ? ' selected' : '') . '>' . e($c['nom']) . '</option>';
    }
    return $html . '</select>';
}
?>

<section class="entete-page">
    <h1>Galerie photos</h1>
    <p class="discret"><?= count($photos) ?> photo<?= count($photos) > 1 ? 's' : '' ?>, dont <?= $nbVisibles ?> visible<?= $nbVisibles > 1 ? 's' : '' ?> sur le site.
        <a href="<?= BASE_URL ?>/galerie" target="_blank">Voir la galerie</a></p>
</section>

<form method="post" enctype="multipart/form-data" class="resa-bloc galerie-envoi">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="ajouter">
    <h2>Ajouter des photos</h2>
    <div class="champ">
        <label for="photos">Photos (JPG, PNG ou WebP, <?= round($limite / 1024 / 1024) ?> Mo max chacune)</label>
        <input type="file" id="photos" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple required>
        <span class="discret petit-note">Tu peux en choisir plusieurs d'un coup. Elles sont redimensionnées automatiquement.</span>
    </div>
    <div class="ligne-2">
        <div class="champ">
            <label for="categorie-ajout">Catégorie</label>
            <?= select_categories($categories, null, 'categorie-ajout') ?>
        </div>
        <div class="champ">
            <label for="legende-ajout">Légende (facultatif)</label>
            <input type="text" id="legende-ajout" name="legende" maxlength="150" placeholder="Ex. French manucure, nail art fleuri">
        </div>
    </div>
    <button type="submit" class="btn btn-plein">Envoyer</button>
</form>

<?php if (!$photos): ?>
    <p class="discret">Aucune photo pour l'instant. Ajoute tes plus belles réalisations !</p>
<?php else: ?>
    <div class="galerie-admin">
        <?php foreach ($photos as $i => $ph): ?>
            <article class="photo-admin<?= $ph['visible'] ? '' : ' photo-masquee' ?>" id="photo-<?= (int) $ph['id'] ?>">
                <a href="<?= e(url_photo($ph['fichier'])) ?>" target="_blank" class="photo-admin-image">
                    <img src="<?= e(url_photo($ph['fichier'], true)) ?>" alt="<?= e($ph['legende'] ?? '') ?>" loading="lazy">
                    <?php if (!$ph['visible']): ?><span class="badge badge-annule">Masquée</span><?php endif; ?>
                </a>

                <form method="post" class="photo-admin-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="modifier">
                    <input type="hidden" name="id" value="<?= (int) $ph['id'] ?>">
                    <label class="sr-only" for="leg-<?= (int) $ph['id'] ?>">Légende</label>
                    <input type="text" id="leg-<?= (int) $ph['id'] ?>" name="legende" maxlength="150" value="<?= e($ph['legende'] ?? '') ?>" placeholder="Légende">
                    <label class="sr-only" for="cat-<?= (int) $ph['id'] ?>">Catégorie</label>
                    <?= select_categories($categories, $ph['categorie_id'] !== null ? (int) $ph['categorie_id'] : null, 'cat-' . (int) $ph['id']) ?>
                    <button type="submit" class="btn-action">Enregistrer</button>
                </form>

                <div class="photo-admin-actions">
                    <?php foreach ([
                        ['monter', '↑', 'Monter', $i > 0],
                        ['descendre', '↓', 'Descendre', $i < count($photos) - 1],
                    ] as [$act, $icone, $titre, $actif]): ?>
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="<?= $act ?>">
                            <input type="hidden" name="id" value="<?= (int) $ph['id'] ?>">
                            <button type="submit" class="btn-action" title="<?= $titre ?>" aria-label="<?= $titre ?>" <?= $actif ? '' : 'disabled' ?>><?= $icone ?></button>
                        </form>
                    <?php endforeach; ?>
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="visibilite">
                        <input type="hidden" name="id" value="<?= (int) $ph['id'] ?>">
                        <button type="submit" class="btn-action"><?= $ph['visible'] ? 'Masquer' : 'Afficher' ?></button>
                    </form>
                    <form method="post" data-confirmer="Supprimer définitivement cette photo ?">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="supprimer">
                        <input type="hidden" name="id" value="<?= (int) $ph['id'] ?>">
                        <button type="submit" class="btn-action btn-action-danger">Supprimer</button>
                    </form>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/admin_footer.php';
