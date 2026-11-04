<?php
/**
 * Encadré "Avant de réserver" : règles d'annulation, de déplacement et d'acompte.
 * Inclus dans reserver.php et paiement/initier.php, avant toute confirmation.
 */
require_once __DIR__ . '/legal.php';
?>
<div class="conditions-resume">
    <p class="conditions-titre">Avant de confirmer</p>
    <ul>
        <li>Annulation ou déplacement <strong>gratuits jusqu'à <?= DELAI_ANNULATION_H ?> h avant</strong> le rendez-vous, depuis ton compte.</li>
        <li>L'acompte en ligne est facultatif. Il est <strong>remboursé</strong> si tu annules dans ce délai, ou si l'institut annule.</li>
        <li>Moins de <?= DELAI_ANNULATION_H ?> h avant, ou en cas d'absence, l'acompte reste acquis à l'institut.</li>
        <li>Au-delà de <?= RETARD_TOLERE_MIN ?> min de retard, le soin peut être écourté ou reporté.</li>
    </ul>
    <p class="discret">En réservant, tu acceptes les <a href="<?= BASE_URL ?>/conditions" target="_blank">conditions de réservation</a>.</p>
</div>
