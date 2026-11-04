<?php
/**
 * Petites icônes au trait (SVG en ligne) : elles prennent la couleur du texte.
 * Usage : <?= icone('accueil') ?>
 */
function icone(string $nom, int $taille = 18): string
{
    static $traces = [
        'accueil'     => '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V20a1 1 0 0 0 1 1h4v-6h4v6h4a1 1 0 0 0 1-1V9.5"/>',
        'soins'       => '<path d="M12 3l1.8 4.7L18.5 9.5l-4.7 1.8L12 16l-1.8-4.7L5.5 9.5l4.7-1.8z"/><path d="M19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8z"/>',
        'calendrier'  => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/><path d="M8 14h2M14 14h2M8 17h2"/>',
        'galerie'     => '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="m21 16-5-5-9 9"/>',
        'contact'     => '<path d="M21 12a8.5 8.5 0 0 1-12.6 7.4L3 21l1.6-5.2A8.5 8.5 0 1 1 21 12z"/>',
        'admin'       => '<path d="M12 3 4.5 6v5.5c0 4.6 3.2 8.3 7.5 9.5 4.3-1.2 7.5-4.9 7.5-9.5V6z"/><path d="m9 12 2 2 4-4"/>',
        'connexion'   => '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 3.6-7 8-7s8 3 8 7"/>',
        'inscription' => '<circle cx="10" cy="8" r="4"/><path d="M2 21c0-4 3.6-7 8-7 1.3 0 2.5.3 3.6.7"/><path d="M19 14v6M16 17h6"/>',
        'deconnexion' => '<path d="M9 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h3"/><path d="M16 17l5-5-5-5M21 12H9"/>',
    ];
    $trace = $traces[$nom] ?? '';
    return '<svg class="icone-trait" width="' . $taille . '" height="' . $taille . '" viewBox="0 0 24 24" fill="none" '
        . 'stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
        . $trace . '</svg>';
}

/** Initiales pour l'avatar : "Awa Dossou" -> "AD". */
function initiales(array $utilisateur): string
{
    $i = '';
    foreach ([$utilisateur['prenom'] ?? '', $utilisateur['nom'] ?? ''] as $mot) {
        $mot = trim($mot);
        if ($mot !== '') {
            $i .= mb_strtoupper(mb_substr($mot, 0, 1));
        }
    }
    return $i !== '' ? $i : '?';
}
