<?php
/**
 * Coordonnées de contact : WhatsApp, téléphone, adresse, carte.
 * Rien de secret : ce fichier est versionné.
 */

// Numéro WhatsApp de l'institut. Vide = on reprend SALON_TELEPHONE (config.php).
const SALON_WHATSAPP = '62874404';                 // ex. '01 97 00 00 00'

// Position GPS pour la carte, "latitude,longitude" (clic droit sur Google Maps > copier les coordonnées).
// Vide = pas de carte, juste un lien "Ouvrir dans Google Maps" construit depuis l'adresse.
const SALON_GPS = '6.3776546, 2.4645233';                      // ex. '6.3703,2.4140'

// Message pré-rempli par défaut quand on ouvre WhatsApp
const WHATSAPP_MESSAGE_DEFAUT = 'Bonjour, j\'ai une question pour ' . APP_NAME . '.';

/**
 * Numéro béninois au format international sans "+" (pour wa.me).
 * Accepte "01 97 00 00 00", "+229 01 97 00 00 00", "97000000" (ancien format à 8 chiffres).
 */
function numero_international(string $numero): string
{
    $chiffres = preg_replace('/\D+/', '', $numero);
    if (str_starts_with($chiffres, '00229')) {
        $chiffres = substr($chiffres, 2);
    }
    if (str_starts_with($chiffres, '229') && strlen($chiffres) >= 11) {
        $chiffres = substr($chiffres, 3);
    }
    if (strlen($chiffres) === 8) {        // ancien numéro : on ajoute le "01"
        $chiffres = '01' . $chiffres;
    }
    return strlen($chiffres) === 10 ? '229' . $chiffres : '';
}

/** Numéro WhatsApp de l'institut, '' s'il n'est pas configuré. */
function whatsapp_institut(): string
{
    return numero_international(SALON_WHATSAPP !== '' ? SALON_WHATSAPP : SALON_TELEPHONE);
}

/**
 * Lien wa.me avec message pré-rempli.
 * Sans $numero : numéro de l'institut. Renvoie '' si aucun numéro valable.
 */
function lien_whatsapp(?string $message = null, ?string $numero = null): string
{
    $international = $numero === null ? whatsapp_institut() : numero_international($numero);
    if ($international === '') {
        return '';
    }
    $message = $message ?? WHATSAPP_MESSAGE_DEFAUT;
    return 'https://wa.me/' . $international . ($message !== '' ? '?text=' . rawurlencode($message) : '');
}

/** Lien Google Maps vers l'institut (GPS si connu, sinon adresse). */
function lien_carte(): string
{
    $cible = SALON_GPS !== '' ? SALON_GPS : trim(SALON_ADRESSE . ' ' . 'Bénin');
    if (SALON_GPS === '' && SALON_ADRESSE === '') {
        return '';
    }
    return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($cible);
}

/** URL de la carte OpenStreetMap intégrable (sans cookie publicitaire), '' sans GPS. */
function carte_integree(): string
{
    if (!preg_match('/^\s*(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)\s*$/', SALON_GPS, $m)) {
        return '';
    }
    [$lat, $lon] = [(float) $m[1], (float) $m[2]];
    $d = 0.006;   // environ 600 m autour du point
    $bbox = implode(',', [$lon - $d, $lat - $d, $lon + $d, $lat + $d]);
    return 'https://www.openstreetmap.org/export/embed.html?bbox=' . rawurlencode($bbox)
        . '&layer=mapnik&marker=' . rawurlencode($lat . ',' . $lon);
}

/** Icône bulle de discussion (SVG en ligne, hérite de la couleur du texte). */
function icone_discussion(): string
{
    return '<svg class="icone" viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false">'
        . '<path fill="currentColor" d="M12 3C6.9 3 3 6.6 3 11c0 1.9.7 3.6 1.9 5L4 21l5.1-1.7c.9.3 1.9.5 2.9.5 '
        . '5.1 0 9-3.6 9-8.4S17.1 3 12 3zm-4 9.3a1.3 1.3 0 1 1 0-2.6 1.3 1.3 0 0 1 0 2.6zm4 0a1.3 1.3 0 1 1 0-2.6 '
        . '1.3 1.3 0 0 1 0 2.6zm4 0a1.3 1.3 0 1 1 0-2.6 1.3 1.3 0 0 1 0 2.6z"/></svg>';
}
