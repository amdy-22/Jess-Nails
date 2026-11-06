<?php
/**
 * Conditions de réservation (annulation, déplacement, acompte, retard, absence).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/reservation.php';
require_once __DIR__ . '/../includes/legal.php';

$pageTitle  = 'Conditions de réservation';
$activePage = '';
require __DIR__ . '/../includes/header.php';
?>

<article class="page-legale">
    <h1>Conditions de réservation</h1>
    <p class="discret">En vigueur au <?= format_date_fr(new DateTimeImmutable('2026-11-02')) ?>.</p>

    <h2>1. Réserver un soin</h2>
    <p>La réservation en ligne nécessite un compte client. <?php if (acompte_en_ligne_actif()): ?>Le rendez-vous est confirmé dès que vous choisissez votre mode de règlement : tout sur place, ou acompte en ligne.<?php else: ?>Le rendez-vous est confirmé immédiatement, le règlement se fait à l'institut.<?php endif; ?> Un e-mail de confirmation vous est envoyé.</p>
    <p>Un créneau choisi reste bloqué pendant <?= EXPIRATION_ATTENTE ?> minutes le temps de confirmer. Passé ce délai sans confirmation, il redevient disponible.</p>
    <p>Les prix sont indiqués en francs CFA (FCFA), toutes taxes comprises. Le prix applicable est celui affiché au moment de la réservation.</p>

    <?php if (acompte_en_ligne_actif()): ?>
    <h2>2. Acompte en ligne</h2>
    <p>L'acompte est <strong>facultatif</strong>. Il est payé par Mobile Money (MTN MoMo, Moov Money) ou carte bancaire via notre prestataire de paiement FedaPay. Nous ne recevons ni ne conservons vos coordonnées bancaires ou votre code Mobile Money.</p>
    <p>Le solde est réglé à l'institut le jour du rendez-vous. Vous pouvez aussi payer l'acompte plus tard depuis votre compte, tant que le rendez-vous n'a pas eu lieu.</p>

    <?php else: ?>
    <h2>2. Règlement</h2>
    <p>Le règlement s'effectue à l'institut, le jour du rendez-vous. Aucun paiement n'est demandé en ligne pour le moment.</p>
    <?php endif; ?>

    <h2>3. Annuler ou déplacer un rendez-vous</h2>
    <p>Vous pouvez annuler ou déplacer votre rendez-vous gratuitement, depuis votre espace « Mon compte », <strong>jusqu'à <?= DELAI_ANNULATION_H ?> heures avant</strong> l'heure prévue.</p>
    <p>Si vous annulez dans ce délai, l'acompte éventuellement payé vous est remboursé intégralement, sur le moyen de paiement utilisé ou en espèces à l'institut, dans un délai de 14 jours.</p>
    <p>Moins de <?= DELAI_ANNULATION_H ?> heures avant le rendez-vous, l'annulation en ligne n'est plus possible : contactez l'institut<?= SALON_TELEPHONE !== '' ? ' au ' . e(SALON_TELEPHONE) : '' ?>. L'acompte reste alors acquis à l'institut, sauf cas de force majeure dûment justifié.</p>

    <h2>4. Retard et absence</h2>
    <p>Merci d'arriver quelques minutes avant l'heure. Au-delà de <?= RETARD_TOLERE_MIN ?> minutes de retard, le soin peut être écourté pour ne pas pénaliser la cliente suivante, au même tarif, ou reporté selon les disponibilités.</p>
    <p>En cas d'absence sans prévenir, l'acompte éventuellement payé reste acquis à l'institut. Après plusieurs absences, l'institut peut demander un acompte pour les réservations suivantes.</p>

    <h2>5. Annulation par l'institut</h2>
    <p>Si l'institut doit annuler ou déplacer votre rendez-vous (praticienne indisponible, fermeture exceptionnelle), vous êtes prévenue par e-mail. Vous pouvez accepter le nouveau créneau ou annuler : l'acompte est alors remboursé intégralement.</p>

    <h2>6. Santé et contre-indications</h2>
    <p>Signalez-nous à la réservation (champ « Une précision ? ») ou à votre arrivée toute allergie, grossesse, peau sensible ou traitement en cours. La praticienne peut adapter ou refuser un soin si votre sécurité l'exige.</p>

    <h2>7. Réclamations</h2>
    <p>Pour toute réclamation, écrivez à <a href="mailto:<?= e(email_contact()) ?>"><?= e(email_contact()) ?></a><?= SALON_TELEPHONE !== '' ? ' ou appelez le ' . e(SALON_TELEPHONE) : '' ?>. Nous recherchons une solution amiable avant tout recours. Ces conditions sont soumises au droit béninois.</p>

    <p class="liens-legaux">Voir aussi : <a href="<?= BASE_URL ?>/mentions-legales">Mentions légales</a> · <a href="<?= BASE_URL ?>/confidentialite">Confidentialité</a></p>
</article>

<?php require __DIR__ . '/../includes/footer.php';
