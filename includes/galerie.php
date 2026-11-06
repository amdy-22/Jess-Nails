<?php
/**
 * Galerie photos et vidéos : traitement des fichiers envoyés et lecture.
 * Chaque photo est ré-enregistrée en deux tailles (grande + vignette) au format JPEG :
 * les photos de téléphone (souvent 4 à 8 Mo) deviennent légères, et un fichier piégé ne passe pas.
 * Les vidéos (MP4, WebM, MOV) sont vérifiées puis conservées telles quelles ; une image de couverture est
 * extraite avec ffmpeg quand il est installé sur le serveur, sinon le navigateur affiche la première image.
 */

const GALERIE_DOSSIER     = __DIR__ . '/../public/assets/images/galerie';
const GALERIE_URL         = '/assets/images/galerie';     // à la suite de BASE_URL
const GALERIE_MAX_OCTETS  = 15 * 1024 * 1024;              // 15 Mo par photo (avant réduction)
const GALERIE_GRANDE_PX   = 1600;                          // plus grand côté de la grande image
const GALERIE_VIGNETTE_PX = 600;                           // plus grand côté de la vignette
const GALERIE_QUALITE     = 82;                            // qualité JPEG
const VIDEO_MAX_OCTETS    = 50 * 1024 * 1024;              // 50 Mo par vidéo (clips courts : 15 à 30 secondes)

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
    @unlink(GALERIE_DOSSIER . '/min/' . pathinfo($fichier, PATHINFO_FILENAME) . '.jpg');   // couverture d'une vidéo
}

// =====================================================
// Vidéos
// =====================================================

/** Ce fichier envoyé est-il une vidéo ? (d'après l'extension : la vérification réelle se fait au traitement) */
function est_video_envoyee(array $f): bool
{
    return in_array(strtolower(pathinfo((string) ($f['name'] ?? ''), PATHINFO_EXTENSION)), ['mp4', 'm4v', 'mov', 'webm'], true);
}

/** Cette ligne de la galerie est-elle une vidéo ? */
function est_video(array $ph): bool
{
    return ($ph['type'] ?? 'photo') === 'video';
}

/** Chemin de ffmpeg s'il est utilisable sur ce serveur, sinon ''. */
function chemin_ffmpeg(): string
{
    static $chemin = null;
    if ($chemin !== null) {
        return $chemin;
    }
    $chemin = '';
    $desactivees = array_map('trim', explode(',', (string) ini_get('disable_functions')));
    if (function_exists('exec') && !in_array('exec', $desactivees, true)) {
        $sortie = [];
        @exec('command -v ffmpeg 2>/dev/null', $sortie);
        if (!empty($sortie[0]) && is_executable($sortie[0])) {
            $chemin = $sortie[0];
        }
    }
    return $chemin;
}

/**
 * Dimensions d'affichage d'un MP4/MOV lues dans le fichier (boîte tkhd), rotation du téléphone comprise.
 * Renvoie [largeur, hauteur] ou null si illisible.
 */
function dimensions_mp4(string $chemin): ?array
{
    $fh = @fopen($chemin, 'rb');
    if (!$fh) {
        return null;
    }
    $lire = function (int $debut, int $fin) use ($fh, &$lire): ?array {
        $pos = $debut;
        for ($garde = 0; $pos + 8 <= $fin && $garde < 500; $garde++) {
            fseek($fh, $pos);
            $tete = fread($fh, 8);
            if (strlen($tete) < 8) {
                return null;
            }
            $taille = unpack('N', substr($tete, 0, 4))[1];
            $type   = substr($tete, 4, 4);
            $entete = 8;
            if ($taille === 1) {
                $etendue = fread($fh, 8);
                $taille  = unpack('J', $etendue)[1];
                $entete  = 16;
            } elseif ($taille === 0) {
                $taille = $fin - $pos;
            }
            if ($taille < $entete) {
                return null;
            }
            if ($type === 'moov' || $type === 'trak') {
                if ($r = $lire($pos + $entete, min($fin, $pos + $taille))) {
                    return $r;
                }
            } elseif ($type === 'tkhd') {
                $c = fread($fh, min(100, $taille - $entete));
                $v = ord($c[0] ?? "\0");
                $m = $v === 1 ? 52 : 40;                    // début de la matrice
                if (strlen($c) >= $m + 44) {
                    $a = unpack('l', strrev(substr($c, $m, 4)))[1] ?? 0;
                    $d = unpack('l', strrev(substr($c, $m + 16, 4)))[1] ?? 0;
                    $l = unpack('N', substr($c, $m + 36, 4))[1] >> 16;
                    $h = unpack('N', substr($c, $m + 40, 4))[1] >> 16;
                    if ($l > 0 && $h > 0) {                 // une piste audio a 0 x 0 : on l'ignore
                        return ($a === 0 && $d === 0) ? [$h, $l] : [$l, $h];   // 90° ou 270° : on échange
                    }
                }
            }
            $pos += $taille;
        }
        return null;
    };
    $r = $lire(0, filesize($chemin));
    fclose($fh);
    return $r;
}

/**
 * Traite une vidéo envoyée : vérification du vrai format, enregistrement, image de couverture.
 * Renvoie ['fichier' => 'abc.mp4', 'largeur' => .., 'hauteur' => ..]. Lève une RuntimeException lisible sinon.
 */
function traiter_video(array $f): array
{
    $nomOrigine = $f['name'] ?? 'vidéo';
    $erreur = (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($erreur === UPLOAD_ERR_INI_SIZE || $erreur === UPLOAD_ERR_FORM_SIZE) {
        throw new RuntimeException($nomOrigine . ' : trop lourde pour le serveur (limite : ' . ini_get('upload_max_filesize') . ').');
    }
    if ($erreur !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
        throw new RuntimeException($nomOrigine . ' : l\'envoi a échoué.');
    }
    if ($f['size'] > VIDEO_MAX_OCTETS) {
        throw new RuntimeException($nomOrigine . ' : dépasse ' . (VIDEO_MAX_OCTETS / 1024 / 1024) . ' Mo. Envoie un clip plus court ou compressé.');
    }

    // Le vrai format se lit dans le contenu, pas dans le nom du fichier
    $debut = (string) @file_get_contents($f['tmp_name'], false, null, 0, 16);
    if (substr($debut, 4, 4) === 'ftyp') {
        $extension = str_starts_with(substr($debut, 8, 4), 'qt') ? 'mov' : 'mp4';
    } elseif (str_starts_with($debut, "\x1A\x45\xDF\xA3")) {
        $extension = 'webm';
    } else {
        throw new RuntimeException($nomOrigine . ' : format non reconnu. Formats acceptés : MP4, MOV ou WebM.');
    }

    foreach (['', '/min'] as $sous) {
        if (!is_dir(GALERIE_DOSSIER . $sous)) {
            mkdir(GALERIE_DOSSIER . $sous, 0775, true);
        }
    }
    $base = bin2hex(random_bytes(10));
    $nom  = $base . '.' . $extension;
    $dest = GALERIE_DOSSIER . '/' . $nom;
    if (!move_uploaded_file($f['tmp_name'], $dest)) {
        throw new RuntimeException($nomOrigine . ' : impossible d\'enregistrer la vidéo sur le serveur.');
    }
    @chmod($dest, 0644);

    $largeur = $hauteur = 0;
    // Image de couverture (ffmpeg), prise à 0,5 s, orientée comme la vidéo
    if ($ffmpeg = chemin_ffmpeg()) {
        $poster = GALERIE_DOSSIER . '/min/' . $base . '.jpg';
        $cmd = escapeshellarg($ffmpeg) . ' -y -loglevel error -ss 0.5 -i ' . escapeshellarg($dest)
            . ' -frames:v 1 -vf ' . escapeshellarg('scale=\'min(' . GALERIE_VIGNETTE_PX . ',iw)\':-2') . ' -q:v 4 ' . escapeshellarg($poster) . ' 2>&1';
        @exec($cmd);
        if (!is_file($poster) || filesize($poster) === 0) {   // vidéo de moins de 0,5 s : première image
            @exec(escapeshellarg($ffmpeg) . ' -y -loglevel error -i ' . escapeshellarg($dest) . ' -frames:v 1 -vf '
                . escapeshellarg('scale=\'min(' . GALERIE_VIGNETTE_PX . ',iw)\':-2') . ' -q:v 4 ' . escapeshellarg($poster) . ' 2>&1');
        }
        if (is_file($poster) && ($i = @getimagesize($poster))) {
            [$largeur, $hauteur] = $i;
        } else {
            @unlink($poster);
        }
    }
    if ($largeur === 0 && $extension !== 'webm' && ($d = dimensions_mp4($dest))) {
        [$largeur, $hauteur] = $d;
    }
    if ($largeur === 0) {
        [$largeur, $hauteur] = [16, 9];   // format inconnu : paysage par défaut
    }

    return ['fichier' => $nom, 'largeur' => min(65535, $largeur), 'hauteur' => min(65535, $hauteur)];
}

/** URL de l'image de couverture d'une vidéo, ou '' si le serveur n'a pas pu en créer une. */
function url_couverture(string $fichier): string
{
    $base = pathinfo(basename($fichier), PATHINFO_FILENAME);
    return is_file(GALERIE_DOSSIER . '/min/' . $base . '.jpg') ? BASE_URL . GALERIE_URL . '/min/' . rawurlencode($base) . '.jpg' : '';
}

/**
 * Vignette HTML d'un élément de la galerie : <img> pour une photo ; pour une vidéo, sa couverture
 * ou, à défaut, la première image affichée par le navigateur.
 */
function vignette_html(array $ph, string $alt, bool $paresseux = true): string
{
    $attrs = ($ph['largeur'] ?? 0) && ($ph['hauteur'] ?? 0) ? ' width="' . (int) $ph['largeur'] . '" height="' . (int) $ph['hauteur'] . '"' : '';
    $lazy  = $paresseux ? ' loading="lazy"' : '';
    if (!est_video($ph)) {
        return '<img src="' . e(url_photo($ph['fichier'], true)) . '" alt="' . e($alt) . '"' . $attrs . $lazy . '>';
    }
    if ($couv = url_couverture($ph['fichier'])) {
        return '<img src="' . e($couv) . '" alt="' . e($alt) . '"' . $attrs . $lazy . '><span class="video-lecture" aria-hidden="true"></span>';
    }
    return '<video src="' . e(url_photo($ph['fichier'])) . '#t=0.5" preload="metadata" muted playsinline tabindex="-1" aria-label="' . e($alt) . '"></video>'
        . '<span class="video-lecture" aria-hidden="true"></span>';
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
