<?php
/**
 * Pied de page commun.
 * Sur ordinateur : rubriques en colonnes. Sur téléphone : rubriques repliables (accordéon),
 * puis contact, copyright et liens légaux.
 */
if (is_file(__DIR__ . '/contact.php')) {
    require_once __DIR__ . '/contact.php';
}
$lienWhatsapp = function_exists('lien_whatsapp') ? lien_whatsapp($messageWhatsapp ?? null) : '';
$categoriesPied = db()->query('SELECT nom, slug FROM categories ORDER BY ordre, id')->fetchAll();
$utilisateurPied = current_user();
?>
    </div>
</main>

<footer class="site-footer">
    <div class="conteneur">

        <div class="pied-groupes">
            <details class="pied-groupe" open>
                <summary>Prestations</summary>
                <ul>
                    <?php foreach ($categoriesPied as $cat): ?>
                        <li><a href="<?= BASE_URL ?>/prestations#<?= e($cat['slug']) ?>"><?= e($cat['nom']) ?></a></li>
                    <?php endforeach; ?>
                    <li><a href="<?= BASE_URL ?>/prestations">Tous les soins et tarifs</a></li>
                    <li><a href="<?= BASE_URL ?>/galerie">Galerie photos</a></li>
                </ul>
            </details>

            <details class="pied-groupe" open>
                <summary>Réservation</summary>
                <ul>
                    <li><a href="<?= BASE_URL ?>/reserver">Réserver une séance</a></li>
                    <?php if ($utilisateurPied): ?>
                        <li><a href="<?= BASE_URL ?>/profile">Mes rendez-vous</a></li>
                    <?php else: ?>
                        <li><a href="<?= BASE_URL ?>/connexion">Se connecter</a></li>
                        <li><a href="<?= BASE_URL ?>/inscription">Créer un compte</a></li>
                    <?php endif; ?>
                    <li><a href="<?= BASE_URL ?>/conditions">Annulation et acompte</a></li>
                </ul>
            </details>

            <details class="pied-groupe" open>
                <summary>Horaires</summary>
                <ul>
                    <?php foreach (resume_horaires() as $ligneHoraire): ?>
                        <li><?= e($ligneHoraire) ?></li>
                    <?php endforeach; ?>
                </ul>
            </details>

            <details class="pied-groupe" open>
                <summary>Nous trouver</summary>
                <ul>
                    <?php if (SALON_ADRESSE !== ''): ?><li><?= e(SALON_ADRESSE) ?></li><?php endif; ?>
                    <li><a href="<?= BASE_URL ?>/contact">Plan d'accès et contact</a></li>
                    <?php if ($lienWhatsapp): ?>
                        <li><a href="<?= e($lienWhatsapp) ?>" target="_blank" rel="noopener">Écrire sur WhatsApp</a></li>
                    <?php endif; ?>
                </ul>
            </details>
        </div>

        <?php
        // Phrase de contact, construite d'un bloc pour éviter les espaces parasites avant la ponctuation
        $moyens = [$lienWhatsapp
            ? '<a href="' . e($lienWhatsapp) . '" target="_blank" rel="noopener">sur WhatsApp</a>'
            : '<a href="' . BASE_URL . '/contact">en nous contactant</a>'];
        if (SALON_TELEPHONE !== '') {
            $moyens[] = 'appeler le <a href="tel:' . e(SALON_TELEPHONE) . '">' . e(SALON_TELEPHONE) . '</a>';
        }
        ?>
        <p class="pied-phrase">Vous pouvez aussi réserver <?= implode(' ou ', $moyens) ?>. Règlement sur place, ou acompte en ligne par Mobile Money.</p>

        <p class="pied-lieu">Cotonou, Bénin</p>

        <div class="pied-legal">
            <p>Copyright &copy; <?= date('Y') ?> <?= e(APP_NAME) ?>. Tous droits réservés.</p>
            <nav aria-label="Informations légales">
                <a href="<?= BASE_URL ?>/conditions">Conditions de réservation</a>
                <a href="<?= BASE_URL ?>/confidentialite">Confidentialité</a>
                <a href="<?= BASE_URL ?>/mentions-legales">Mentions légales</a>
                <a href="<?= BASE_URL ?>/contact">Contact</a>
            </nav>
        </div>
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
