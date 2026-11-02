<?php
/**
 * Informations légales affichées sur les pages Mentions légales, Confidentialité et Conditions.
 * Rien de secret ici : ce fichier est versionné. Remplace les valeurs "À compléter".
 */

// ---------- Éditeur du site (l'institut) ----------
const EDITEUR_NOM        = 'À compléter';        // nom de l'entreprise ou de la gérante, ex. 'Jess Nails (Jessica X)'
const EDITEUR_FORME      = 'À compléter';        // ex. 'Entreprise individuelle', 'SARL au capital de 1 000 000 FCFA'
const EDITEUR_RCCM       = 'À compléter';        // ex. 'RB/COT/24 A 12345'
const EDITEUR_IFU        = 'À compléter';        // 13 chiffres
const EDITEUR_RESPONSABLE = 'À compléter';       // directrice de la publication (nom et prénom)
const EDITEUR_EMAIL      = '';                    // vide = MAIL_FROM_EMAIL
const APDP_DECLARATION   = '';                    // numéro de récépissé APDP, vide tant qu'il n'est pas obtenu

// ---------- Hébergeur ----------
const HEBERGEUR_NOM      = 'alwaysdata SARL';
const HEBERGEUR_ADRESSE  = '91 rue du Faubourg Saint-Honoré, 75008 Paris, France';  // à vérifier sur alwaysdata.com
const HEBERGEUR_SITE     = 'https://www.alwaysdata.com';

// ---------- Règles de réservation ----------
const RETARD_TOLERE_MIN  = 15;   // au-delà de ce retard, le soin peut être écourté ou reporté

function email_contact(): string
{
    return EDITEUR_EMAIL !== '' ? EDITEUR_EMAIL : MAIL_FROM_EMAIL;
}

/** Affiche une valeur, ou un repère visible si elle reste à compléter. */
function info_legale(string $valeur): string
{
    $valeur = trim($valeur);
    if ($valeur === '' || $valeur === 'À compléter') {
        return '<span class="a-completer">À compléter</span>';
    }
    return e($valeur);
}
