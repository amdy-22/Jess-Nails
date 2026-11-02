<?php
/**
 * Accueil public (version provisoire de l'étape 3).
 * Le catalogue complet arrive à l'étape 4.
 */
require_once __DIR__ . '/../includes/auth.php';

$categories = db()->query(
    'SELECT c.nom, c.slug, COUNT(p.id) AS nb, MIN(p.prix_fcfa) AS prix_min
     FROM categories c
     LEFT JOIN prestations p ON p.categorie_id = c.id AND p.actif = 1
     GROUP BY c.id
     ORDER BY c.ordre'
)->fetchAll();

$pageTitle  = 'Accueil';
$activePage = 'accueil';
require __DIR__ . '/../includes/header.php';
?>

<section class="accueil-intro">
    <h1>Prenez soin de vous, on s'occupe du reste.</h1>
    <p>Soins du visage, manucure et pédicure. Choisissez votre soin et votre créneau en ligne, puis réglez sur place ou par Mobile Money.</p>
    <div class="actions">
        <a href="<?= BASE_URL ?>/reserver.php" class="btn btn-plein">Réserver une séance</a>
        <a href="<?= BASE_URL ?>/prestations.php" class="btn">Voir les prestations</a>
    </div>
</section>

<section class="grille-categories">
    <?php foreach ($categories as $cat): ?>
        <a class="carte-categorie" href="<?= BASE_URL ?>/prestations.php#<?= e($cat['slug']) ?>">
            <h2><?= e($cat['nom']) ?></h2>
            <p class="discret"><?= (int) $cat['nb'] ?> soin<?= $cat['nb'] > 1 ? 's' : '' ?></p>
            <?php if ($cat['prix_min'] !== null): ?>
                <p class="prix">À partir de <?= format_fcfa((int) $cat['prix_min']) ?></p>
            <?php endif; ?>
        </a>
    <?php endforeach; ?>
</section>

<?php require __DIR__ . '/../includes/footer.php';?>