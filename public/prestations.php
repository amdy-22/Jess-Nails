<?php
/**
 * Catalogue public des prestations, groupées par catégorie.
 * Chaque soin mène à reserver.php?prestation=ID (connexion demandée à ce moment-là).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/contact.php';

$lignes = db()->query(
    'SELECT c.id AS cat_id, c.nom AS cat_nom, c.slug,
            p.id, p.nom, p.description, p.duree_minutes, p.prix_fcfa, p.acompte_fcfa, p.image
     FROM categories c
     JOIN prestations p ON p.categorie_id = c.id AND p.actif = 1
     ORDER BY c.ordre, p.prix_fcfa'
)->fetchAll();

// Regroupe les soins par catégorie
$categories = [];
foreach ($lignes as $l) {
    $categories[$l['cat_id']]['nom']    = $l['cat_nom'];
    $categories[$l['cat_id']]['slug']   = $l['slug'];
    $categories[$l['cat_id']]['soins'][] = $l;
}

$pageTitle  = 'Prestations';
$activePage = 'prestations';
require __DIR__ . '/../includes/header.php';
?>

<section class="entete-page">
    <h1>Nos prestations</h1>
    <p class="discret">Choisissez votre soin. Vous réglez sur place, ou réglez un acompte en ligne par Mobile Money si vous préférez.</p>

    <?php if ($categories): ?>
        <nav class="filtres" aria-label="Catégories">
            <?php foreach ($categories as $cat): ?>
                <a href="#<?= e($cat['slug']) ?>" class="pastille"><?= e($cat['nom']) ?></a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>
</section>

<?php if (!$categories): ?>
    <p class="alerte alerte-info">Aucune prestation n'est disponible pour le moment.</p>
<?php endif; ?>

<?php foreach ($categories as $cat): ?>
    <section class="bloc-categorie" id="<?= e($cat['slug']) ?>">
        <h2><?= e($cat['nom']) ?></h2>

        <div class="liste-soins">
            <?php foreach ($cat['soins'] as $soin): ?>
                <article class="carte-soin">
                    <?php if ($soin['image']): ?>
                        <img src="<?= BASE_URL ?>/assets/images/<?= e($soin['image']) ?>" alt="" class="soin-image" loading="lazy">
                    <?php endif; ?>

                    <div class="soin-corps">
                        <h3><?= e($soin['nom']) ?></h3>
                        <?php if ($soin['description']): ?>
                            <p class="discret"><?= e($soin['description']) ?></p>
                        <?php endif; ?>

                        <dl class="soin-infos">
                            <div>
                                <dt>Durée</dt>
                                <dd><?= format_duree((int) $soin['duree_minutes']) ?></dd>
                            </div>
                            <div>
                                <dt>Prix</dt>
                                <dd class="prix"><?= format_fcfa((int) $soin['prix_fcfa']) ?></dd>
                            </div>
                            <?php if ($soin['acompte_fcfa'] > 0): ?>
                                <div>
                                    <dt>Acompte (facultatif)</dt>
                                    <dd><?= format_fcfa((int) $soin['acompte_fcfa']) ?></dd>
                                </div>
                            <?php endif; ?>
                        </dl>
                    </div>

                    <a href="<?= BASE_URL ?>/reserver.php?prestation=<?= (int) $soin['id'] ?>" class="btn btn-plein">
                        Réserver ce soin
                    </a>
                    <?php if ($q = lien_whatsapp('Bonjour, j\'ai une question sur le soin « ' . $soin['nom'] . ' ».')): ?>
                        <a href="<?= e($q) ?>" class="lien-question" target="_blank" rel="noopener">Une question sur ce soin ?</a>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
<?php endforeach; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>