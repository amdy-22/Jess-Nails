<?php
/**
 * Mentions légales : éditeur, hébergeur, propriété intellectuelle.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/legal.php';

$pageTitle  = 'Mentions légales';
$activePage = '';
require __DIR__ . '/../includes/header.php';
?>

<article class="page-legale">
    <h1>Mentions légales</h1>

    <h2>Éditeur du site</h2>
    <dl class="infos-legales">
        <dt>Nom</dt>                <dd><?= info_legale(EDITEUR_NOM) ?></dd>
        <dt>Forme</dt>              <dd><?= info_legale(EDITEUR_FORME) ?></dd>
        <dt>RCCM</dt>               <dd><?= info_legale(EDITEUR_RCCM) ?></dd>
        <dt>IFU</dt>                <dd><?= info_legale(EDITEUR_IFU) ?></dd>
        <dt>Adresse</dt>            <dd><?= info_legale(SALON_ADRESSE) ?></dd>
        <dt>Téléphone</dt>          <dd><?= info_legale(SALON_TELEPHONE) ?></dd>
        <dt>E-mail</dt>             <dd><a href="mailto:<?= e(email_contact()) ?>"><?= e(email_contact()) ?></a></dd>
        <dt>Directrice de la publication</dt> <dd><?= info_legale(EDITEUR_RESPONSABLE) ?></dd>
    </dl>

    <h2>Hébergeur</h2>
    <dl class="infos-legales">
        <dt>Nom</dt>     <dd><?= e(HEBERGEUR_NOM) ?></dd>
        <dt>Adresse</dt> <dd><?= e(HEBERGEUR_ADRESSE) ?></dd>
        <dt>Site</dt>    <dd><a href="<?= e(HEBERGEUR_SITE) ?>" rel="noopener" target="_blank"><?= e(HEBERGEUR_SITE) ?></a></dd>
    </dl>

    <h2>Prestataires techniques</h2>
    <p><?= acompte_en_ligne_actif() ? 'Paiement en ligne : FedaPay (Cotonou, Bénin). ' : '' ?>E-mails transactionnels : Brevo (Paris, France). Ces prestataires n'agissent que pour le fonctionnement du service de réservation.</p>

    <h2>Propriété intellectuelle</h2>
    <p>Les textes, photos, logos et la présentation de ce site appartiennent à <?= e(APP_NAME) ?> ou sont utilisés avec autorisation. Toute reproduction sans accord écrit est interdite.</p>

    <h2>Responsabilité</h2>
    <p>Nous faisons notre possible pour que les informations du site (prestations, prix, horaires) soient exactes et à jour. En cas d'écart, le prix affiché au moment de la réservation fait foi. Le site peut être momentanément indisponible pour maintenance.</p>

    <h2>Droit applicable</h2>
    <p>Ce site est soumis au droit béninois, notamment à la loi n° 2017-20 du 20 avril 2018 portant Code du numérique en République du Bénin.</p>

    <p class="liens-legaux">Voir aussi : <a href="<?= BASE_URL ?>/confidentialite">Confidentialité</a> · <a href="<?= BASE_URL ?>/conditions">Conditions de réservation</a></p>
</article>

<?php require __DIR__ . '/../includes/footer.php';
