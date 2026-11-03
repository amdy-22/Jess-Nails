<?php
/**
 * Galerie photos : traitement des images envoyées et lecture des photos.
 * Chaque photo est ré-enregistrée en deux tailles (grande + vignette) au format JPEG :
 * les photos de téléphone (souvent 4 à 8 Mo) deviennent légères, et un fichier piégé ne passe pas.
 */

const GALERIE_DOSSIER     = __DIR__ . '/../public/assets/images/galerie';
const GALERIE_URL         = '/assets/images/galerie';     // à la suite de BASE_URL
const GALERIE_MAX_OCTETS  = 15 * 1024 * 1024;              // 15 Mo par photo (avant réduction)
const GALERIE_GRANDE_PX   = 1600;                          // plus grand côté de la grande image
const GALERIE_VIGNETTE_PX = 600;                           // plus grand côté de la vignette
const GALERIE_QUALITE     = 82;                            // qualité JPEG

/** Limite d'envoi réelle du serveur, en octets (upload_max_filesize). */
function limite_envoi_serveur(): int
{
    $v = trim((string) ini_get('upload_max_filesize'));
    $n = (int) $v;
    return match (strtolower(substr($v, -1))) {
        'g' => $n * 1024 ** 3,
        'm' => $n * 1024 ** 2,
        'k' => $n * 1024,
        default => $n,
    };
}

/** $_FILES['photos'] (envoi multiple) -> liste de fichiers [name, tmp_name, error, size]. */
function fichiers_envoyes(array $champ): array
{
    if (!isset($champ['name']) || !is_array($champ['name'])) {
        return $champ ? [$champ] : [];
    }
    $liste = [];
    foreach ($champ['name'] as $i => $nom) {
        $liste[] = [
            'name'     => $nom,
            'tmp_name' => $champ['tmp_name'][$i],
            'error'    => $champ['error'][$i],
            'size'     => $champ['size'][$i],
        ];
    }
    return $liste;
}

/**
 * Traite une photo envoyée : vérification, rotation selon l'appareil, réduction, ré-encodage.
 * Renvoie ['fichier' => 'abc123.jpg', 'largeur' => ..., 'hauteur' => ...].
 * Lève une RuntimeException avec un message lisible si la photo ne convient pas.
 */
function traiter_photo(array $f): array
{
    $nomOrigine = $f['name'] ?? 'photo';
    $erreur = (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($erreur === UPLOAD_ERR_INI_SIZE || $erreur === UPLOAD_ERR_FORM_SIZE) {
        throw new RuntimeException($nomOrigine . ' : trop lourde pour le serveur.');
    }
    if ($erreur !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
        throw new RuntimeException($nomOrigine . ' : l\'envoi a échoué.');
    }
    if ($f['size'] > GALERIE_MAX_OCTETS) {
        throw new RuntimeException($nomOrigine . ' : dépasse ' . (GALERIE_MAX_OCTETS / 1024 / 1024) . ' Mo.');
    }

    $infos = @getimagesize($f['tmp_name']);
    $lecteurs = [IMAGETYPE_JPEG => 'imagecreatefromjpeg', IMAGETYPE_PNG => 'imagecreatefrompng', IMAGETYPE_WEBP => 'imagecreatefromwebp'];
    if (!$infos || !isset($lecteurs[$infos[2]]) || !function_exists($lecteurs[$infos[2]])) {
        throw new RuntimeException($nomOrigine . ' : format accepté JPG, PNG ou WebP.');
    }
    if ($infos[0] * $infos[1] > 40_000_000) {
        throw new RuntimeException($nomOrigine . ' : image trop grande (plus de 40 mégapixels).');
    }

    @ini_set('memory_limit', '512M');   // décoder une photo de 12 Mpx demande ~60 Mo
    $source = @$lecteurs[$infos[2]]($f['tmp_name']);
    if (!$source) {
        throw new RuntimeException($nomOrigine . ' : image illisible ou abîmée.');
    }

    // Photos de téléphone : on applique l'orientation enregistrée par l'appareil
    if ($infos[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $exif = @exif_read_data($f['tmp_name']);
        $angle = match ((int) ($exif['Orientation'] ?? 1)) { 3 => 180, 6 => -90, 8 => 90, default => 0 };
        if ($angle !== 0 && ($tournee = imagerotate($source, $angle, 0))) {
            imagedestroy($source);
            $source = $tournee;
        }
    }

    foreach (['', '/min'] as $sous) {
        if (!is_dir(GALERIE_DOSSIER . $sous)) {
            mkdir(GALERIE_DOSSIER . $sous, 0775, true);
        }
    }

    $nom = bin2hex(random_bytes(10)) . '.jpg';
    try {
        [$largeur, $hauteur] = enregistrer_taille($source, GALERIE_DOSSIER . '/' . $nom, GALERIE_GRANDE_PX);
        enregistrer_taille($source, GALERIE_DOSSIER . '/min/' . $nom, GALERIE_VIGNETTE_PX);
    } catch (Throwable $e) {
        supprimer_fichiers_photo($nom);
        throw new RuntimeException($nomOrigine . ' : impossible d\'enregistrer la photo sur le serveur.');
    } finally {
        imagedestroy($source);
    }

    return ['fichier' => $nom, 'largeur' => $largeur, 'hauteur' => $hauteur];
}

/** Réduit l'image (sans l'agrandir) et l'enregistre en JPEG. Renvoie [largeur, hauteur]. */
function enregistrer_taille(GdImage $source, string $chemin, int $maxPx): array
{
    $l = imagesx($source);
    $h = imagesy($source);
    $ratio = min(1, $maxPx / max($l, $h));
    $nl = max(1, (int) round($l * $ratio));
    $nh = max(1, (int) round($h * $ratio));

    $cible = imagecreatetruecolor($nl, $nh);
    imagefill($cible, 0, 0, imagecolorallocate($cible, 255, 255, 255));   // fond blanc pour les PNG transparents
    imagecopyresampled($cible, $source, 0, 0, 0, 0, $nl, $nh, $l, $h);
    imageinterlace($cible, true);   // JPEG progressif : s'affiche vite sur mobile
    $ok = imagejpeg($cible, $chemin, GALERIE_QUALITE);
    imagedestroy($cible);
    if (!$ok) {
        throw new RuntimeException('Écriture impossible : ' . $chemin);
    }
    return [$nl, $nh];
}

function supprimer_fichiers_photo(string $fichier): void
{
    $fichier = basename($fichier);   // jamais de chemin fourni de l'extérieur
    @unlink(GALERIE_DOSSIER . '/' . $fichier);
    @unlink(GALERIE_DOSSIER . '/min/' . $fichier);
}

function url_photo(string $fichier, bool $vignette = false): string
{
    return BASE_URL . GALERIE_URL . ($vignette ? '/min/' : '/') . rawurlencode(basename($fichier));
}

/** Photos visibles, des plus récentes (ordre le plus haut) aux plus anciennes. */
function photos_visibles(?int $categorieId = null, ?int $limite = null): array
{
    $sql = 'SELECT ph.*, c.nom AS categorie, c.slug
            FROM photos ph LEFT JOIN categories c ON c.id = ph.categorie_id
            WHERE ph.visible = 1' . ($categorieId ? ' AND ph.categorie_id = ?' : '') . '
            ORDER BY ph.ordre DESC, ph.id DESC' . ($limite ? ' LIMIT ' . (int) $limite : '');
    $stmt = db()->prepare($sql);
    $stmt->execute($categorieId ? [$categorieId] : []);
    return $stmt->fetchAll();
}
