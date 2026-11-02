<?php
require_once __DIR__ . '/contact.php';
$lienWhatsapp = lien_whatsapp($messageWhatsapp ?? null);
?>

</div>
</main>

<footer class="site-footer">
    <div class="conteneur footer-inner">
        <div>
            <p class="logo"><?= e(APP_NAME) ?></p>
            <p class="discret">Soins du visage, manucure et pédicure à Cotonou.</p>
        </div>
        <div>
            <p class="footer-titre">Horaires</p>
            <?php foreach (resume_horaires() as $ligneHoraire): ?>
                <p class="discret"><?= e($ligneHoraire) ?></p>
            <?php endforeach; ?>
        </div>
        <div>
            <div>
                <p class="footer-titre">Contact</p>
                <?php if ($lienWhatsapp): ?>
                    <p><a href="<?= e($lienWhatsapp) ?>" target="_blank" rel="noopener">WhatsApp</a></p>
                <?php endif; ?>
                <?php if (SALON_TELEPHONE !== ''): ?>
                    <p class="discret"><?= e(SALON_TELEPHONE) ?></p>
                <?php endif; ?>
                <?php if (SALON_ADRESSE !== ''): ?>
                    <p class="discret"><?= e(SALON_ADRESSE) ?></p>
                <?php endif; ?>
                <p><a href="<?= BASE_URL ?>/contact.php">Plan d'accès</a></p>
            </div>
            <p class="footer-titre">Paiement</p>
            <p class="discret">Sur place, ou acompte en ligne par Mobile Money</p>
        </div>
    </div>
    <div class="conteneur footer-bas discret">
        <span>&copy; <?= date('Y') ?> <?= e(APP_NAME) ?></span>
        <nav class="footer-liens" aria-label="Informations légales">
            <a href="<?= BASE_URL ?>/conditions.php">Conditions de réservation</a>
            <a href="<?= BASE_URL ?>/confidentialite.php">Confidentialité</a>
            <a href="<?= BASE_URL ?>/mentions-legales.php">Mentions légales</a>
        </nav>
    </div>
</footer>

<?php if ($lienWhatsapp): ?>
    <a href="<?= e($lienWhatsapp) ?>" class="whatsapp-flottant" target="_blank" rel="noopener" aria-label="Nous écrire sur WhatsApp">
        <?= icone_discussion() ?><span>WhatsApp</span>
    </a>
<?php endif; ?>
<script src="<?= BASE_URL ?>/assets/js/app.js"></script>
</body>
</html>