<?php
/**
 * Page Contact : WhatsApp, téléphone, adresse, horaires et carte.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/contact.php';

$whatsapp = lien_whatsapp();
$carte    = lien_carte();
$integree = carte_integree();

$pageTitle  = 'Contact';
$activePage = 'contact';
require __DIR__ . '/../includes/header.php';
?>

<section class="entete-page">
    <h1>Nous contacter</h1>
    <p class="discret">Une question sur un soin, un tarif ou un rendez-vous ? Le plus rapide, c'est WhatsApp.</p>
</section>

<div class="contact-grille">
    <div class="contact-infos">
        <?php if ($whatsapp): ?>
            <a href="<?= e($whatsapp) ?>" class="btn btn-plein btn-whatsapp" target="_blank" rel="noopener">
                <?= icone_discussion() ?> Écrire sur WhatsApp
            </a>
        <?php endif; ?>

        <dl class="contact-liste">
            <?php if (SALON_TELEPHONE !== ''): ?>
                <div>
                    <dt>Téléphone</dt>
                    <?php $tel = numero_international(SALON_TELEPHONE); ?>
                    <dd><a href="tel:<?= e($tel ? '+' . $tel : SALON_TELEPHONE) ?>"><?= e(SALON_TELEPHONE) ?></a></dd>
                </div>
            <?php endif; ?>
            <?php if (SALON_ADRESSE !== ''): ?>
                <div>
                    <dt>Adresse</dt>
                    <dd>
                        <?= e(SALON_ADRESSE) ?>
                        <?php if ($carte): ?>
                            <br><a href="<?= e($carte) ?>" target="_blank" rel="noopener">Ouvrir dans Google Maps</a>
                        <?php endif; ?>
                    </dd>
                </div>
            <?php endif; ?>
            <div>
                <dt>Horaires</dt>
                <dd>
                    <?php foreach (resume_horaires() as $ligneHoraire): ?>
                        <?= e($ligneHoraire) ?><br>
                    <?php endforeach; ?>
                </dd>
            </div>
        </dl>

        <a href="<?= BASE_URL ?>/reserver.php" class="btn">Réserver en ligne</a>
    </div>

    <?php if ($integree): ?>
        <div class="contact-carte">
            <iframe src="<?= e($integree) ?>" title="Plan d'accès à <?= e(APP_NAME) ?>" loading="lazy"></iframe>
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php';
