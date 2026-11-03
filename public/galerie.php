<?php
/**
 * Galerie photos publique, filtrable par catégorie, avec visionneuse plein écran.
 * Sans JavaScript, chaque vignette ouvre simplement la grande photo.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/galerie.php';

// Catégories qui ont au moins une photo visible
$filtres = db()->query(
    'SELECT c.id, c.nom, c.slug, COUNT(*) AS nb
     FROM photos ph JOIN categories c ON c.id = ph.categorie_id
     WHERE ph.visible = 1 GROUP BY c.id ORDER BY c.ordre, c.id'
)->fetchAll();

$slug = (string) ($_GET['categorie'] ?? '');
$categorieActive = null;
foreach ($filtres as $f) {
    if ($f['slug'] === $slug) {
        $categorieActive = $f;
    }
}
$photos = photos_visibles($categorieActive ? (int) $categorieActive['id'] : null);

$pageTitle  = 'Galerie';
$activePage = 'galerie';
require __DIR__ . '/../includes/header.php';
?>

<section class="entete-page">
    <h1>Nos réalisations</h1>
    <p class="discret">Quelques soins réalisés à l'institut. Une envie ? Réservez le même en ligne.</p>

    <?php if (count($filtres) > 1): ?>
        <nav class="filtres" aria-label="Filtrer par catégorie">
            <a href="<?= BASE_URL ?>/galerie.php" class="pastille<?= $categorieActive ? '' : ' pastille-active' ?>">Tout</a>
            <?php foreach ($filtres as $f): ?>
                <a href="?categorie=<?= e($f['slug']) ?>" class="pastille<?= $categorieActive && $categorieActive['id'] === $f['id'] ? ' pastille-active' : '' ?>">
                    <?= e($f['nom']) ?>
                </a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>
</section>

<?php if (!$photos): ?>
    <p class="discret">Les photos arrivent bientôt.</p>
<?php else: ?>
    <div class="galerie" data-galerie>
        <?php foreach ($photos as $ph): ?>
            <a href="<?= e(url_photo($ph['fichier'])) ?>" class="galerie-item"
               data-legende="<?= e($ph['legende'] ?? '') ?>"
               style="aspect-ratio: <?= max(1, (int) $ph['largeur']) ?> / <?= max(1, (int) $ph['hauteur']) ?>">
                <img src="<?= e(url_photo($ph['fichier'], true)) ?>" alt="<?= e($ph['legende'] ?? 'Réalisation ' . APP_NAME) ?>"
                     width="<?= (int) $ph['largeur'] ?>" height="<?= (int) $ph['hauteur'] ?>" loading="lazy">
                <?php if ($ph['legende']): ?><span class="galerie-legende"><?= e($ph['legende']) ?></span><?php endif; ?>
            </a>
        <?php endforeach; ?>
    </div>

    <p class="galerie-cta">
        <a href="<?= BASE_URL ?>/reserver.php" class="btn btn-plein">Réserver une séance</a>
    </p>
<?php endif; ?>

<!-- Visionneuse (remplie par app.js) -->
<div class="visionneuse" id="visionneuse" hidden role="dialog" aria-modal="true" aria-label="Photo agrandie">
    <button type="button" class="visionneuse-fermer" aria-label="Fermer">&times;</button>
    <button type="button" class="visionneuse-prec" aria-label="Photo précédente">&#8249;</button>
    <figure>
        <img src="" alt="">
        <figcaption></figcaption>
    </figure>
    <button type="button" class="visionneuse-suiv" aria-label="Photo suivante">&#8250;</button>
</div>

<?php require __DIR__ . '/../includes/footer.php';
