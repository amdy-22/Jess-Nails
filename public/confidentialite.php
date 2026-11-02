<?php
/**
 * Politique de confidentialité (Code du numérique du Bénin, livre 5).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/legal.php';

$pageTitle  = 'Confidentialité';
$activePage = '';
require __DIR__ . '/../includes/header.php';
?>

<article class="page-legale">
    <h1>Politique de confidentialité</h1>
    <p class="discret">Comment <?= e(APP_NAME) ?> utilise et protège vos données personnelles.</p>

    <h2>Responsable du traitement</h2>
    <p><?= info_legale(EDITEUR_NOM) ?>, <?= info_legale(SALON_ADRESSE) ?>. Contact : <a href="mailto:<?= e(email_contact()) ?>"><?= e(email_contact()) ?></a>.</p>
    <?php if (APDP_DECLARATION !== ''): ?>
        <p>Traitement déclaré auprès de l'Autorité de Protection des Données à caractère Personnel (APDP), récépissé n° <?= e(APDP_DECLARATION) ?>.</p>
    <?php endif; ?>

    <h2>Données collectées</h2>
    <ul>
        <li><strong>Compte client</strong> : prénom, nom, e-mail, téléphone, mot de passe (enregistré chiffré, jamais lisible).</li>
        <li><strong>Rendez-vous</strong> : soin, date, praticienne, précision éventuelle que vous saisissez.</li>
        <li><strong>Paiement</strong> : montant, statut et référence de la transaction FedaPay. Nous ne voyons ni ne stockons vos numéros de carte ou codes Mobile Money.</li>
    </ul>
    <p>Évitez de saisir des informations médicales détaillées dans le champ « Une précision ? » : indiquez simplement ce que la praticienne doit savoir (ex. « peau sensible »).</p>

    <h2>À quoi elles servent</h2>
    <ul>
        <li>Gérer vos réservations, annulations et déplacements.</li>
        <li>Vous envoyer les e-mails de confirmation, de rappel et de changement de rendez-vous.</li>
        <li>Encaisser et, le cas échéant, rembourser un acompte.</li>
        <li>Respecter nos obligations comptables.</li>
    </ul>
    <p>Aucune publicité, aucune revente de données, aucun envoi commercial sans votre accord.</p>

    <h2>Qui y a accès</h2>
    <p>L'équipe de l'institut, et uniquement pour ce qui la concerne, nos prestataires : alwaysdata (hébergement, France), Brevo (envoi des e-mails, France) et FedaPay (paiement, Bénin). Certaines données sont donc hébergées en France, pays disposant d'une législation protectrice des données personnelles.</p>

    <h2>Durée de conservation</h2>
    <ul>
        <li>Compte client : tant que vous l'utilisez, puis supprimé après 3 ans sans réservation.</li>
        <li>Historique des rendez-vous et des paiements : durée exigée par les obligations comptables.</li>
        <li>Liens de réinitialisation du mot de passe : 1 heure.</li>
    </ul>

    <h2>Cookies</h2>
    <p>Le site utilise un seul cookie, indispensable pour vous garder connectée et sécuriser les formulaires. Il n'y a ni cookie publicitaire ni outil de mesure d'audience.</p>

    <h2>Vos droits</h2>
    <p>Conformément au Code du numérique en République du Bénin, vous pouvez demander l'accès à vos données, leur rectification, leur suppression ou vous opposer à leur traitement. Vous modifiez déjà vos coordonnées depuis « Mon compte ». Pour le reste, écrivez à <a href="mailto:<?= e(email_contact()) ?>"><?= e(email_contact()) ?></a> : nous répondons sous 30 jours.</p>
    <p>Si vous estimez que vos droits ne sont pas respectés, vous pouvez saisir l'APDP (<a href="https://apdp.bj" rel="noopener" target="_blank">apdp.bj</a>).</p>

    <h2>Sécurité</h2>
    <p>Connexion chiffrée (HTTPS), mots de passe chiffrés, accès à l'administration réservé à l'équipe, protection contre les tentatives de connexion répétées.</p>

    <p class="liens-legaux">Voir aussi : <a href="<?= BASE_URL ?>/mentions-legales.php">Mentions légales</a> · <a href="<?= BASE_URL ?>/conditions.php">Conditions de réservation</a></p>
</article>

<?php require __DIR__ . '/../includes/footer.php';
