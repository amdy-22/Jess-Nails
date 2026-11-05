<?php
/**
 * Sauvegarde automatique de la base de données (et des photos de la galerie, une fois par semaine).
 *
 * Tâche planifiée (AlwaysData > Avancé > Tâches planifiées), tous les jours à 3 h :
 *   php $HOME/www/jess-nails/taches/sauvegarde.php
 *
 * À la main :
 *   php taches/sauvegarde.php              sauvegarde maintenant (base, et photos le dimanche)
 *   php taches/sauvegarde.php --photos     sauvegarde la base ET les photos
 *   php taches/sauvegarde.php --liste      affiche les sauvegardes existantes
 *
 * Où vont les fichiers ? Dans, par ordre de priorité :
 *   1. la constante BACKUP_DIR de config/config.php (si tu la définis) ;
 *   2. $HOME/sauvegardes-beauty (hors du dossier du site, donc jamais accessible par internet) ;
 *   3. le dossier sauvegardes/ du projet (fermé au web par un .htaccess).
 *
 * Conservation : les 14 dernières sauvegardes de la base, plus celle du 1er de chaque mois
 * pendant 6 mois. Photos : les 4 dernières archives.
 *
 * En cas d'échec, un email part vers DEVELOPPEUR_EMAIL (config.php) et le
 * script se termine avec un code d'erreur (AlwaysData l'affiche dans l'historique de la tâche).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Ce script se lance uniquement en ligne de commande.');
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/galerie.php';

const SAUVEGARDES_A_GARDER = 14;
const MOIS_A_GARDER        = 6;
const ARCHIVES_PHOTOS      = 4;

// ---------- Dossier de destination ----------
function dossier_sauvegardes(): string
{
    if (defined('BACKUP_DIR') && BACKUP_DIR !== '') {
        $dossier = BACKUP_DIR;
    } elseif (($home = getenv('HOME')) && is_dir($home) && is_writable($home)) {
        $dossier = rtrim($home, '/') . '/sauvegardes-beauty';
    } else {
        $dossier = __DIR__ . '/../sauvegardes';
        if (!is_dir($dossier)) {
            @mkdir($dossier, 0700, true);
        }
        @file_put_contents($dossier . '/.htaccess', "Require all denied\n");
    }
    if (!is_dir($dossier) && !@mkdir($dossier, 0700, true)) {
        throw new RuntimeException('Impossible de créer le dossier ' . $dossier);
    }
    return realpath($dossier);
}

function journal(string $message): void
{
    $ligne = date('Y-m-d H:i:s') . ' ' . $message . "\n";
    echo $ligne;
    @file_put_contents(__DIR__ . '/../logs/sauvegarde.log', $ligne, FILE_APPEND);
}

function taille_lisible(int $octets): string
{
    return $octets >= 1048576 ? round($octets / 1048576, 1) . ' Mo' : max(1, round($octets / 1024)) . ' Ko';
}

// ---------- Commande --liste ----------
if (in_array('--liste', $argv, true)) {
    $dossier = dossier_sauvegardes();
    echo "Dossier : $dossier\n";
    foreach (glob($dossier . '/*.{sql.gz,tar.gz}', GLOB_BRACE) ?: [] as $f) {
        printf("  %-45s %10s\n", basename($f), taille_lisible(filesize($f)));
    }
    exit(0);
}

// ---------- Sauvegarde de la base ----------
function sauvegarder_base(string $dossier): string
{
    $horodatage = date('Y-m-d_H-i');
    $cible      = $dossier . "/base_{$horodatage}.sql.gz";
    $temporaire = $cible . '.partiel';

    // Identifiants dans un fichier temporaire (jamais dans la ligne de commande, visible par les autres comptes)
    $options = tempnam(sys_get_temp_dir(), 'dump');
    chmod($options, 0600);
    file_put_contents($options, "[client]\nuser=" . DB_USER . "\npassword=\"" . addcslashes(DB_PASS, '"\\') . "\"\nhost=" . DB_HOST . "\nport=" . DB_PORT . "\n");

    $commande = sprintf(
        'mysqldump --defaults-extra-file=%s --single-transaction --routines --triggers --default-character-set=utf8mb4 %s 2>&1 | gzip -9 > %s; echo "${PIPESTATUS[0]}"',
        escapeshellarg($options), escapeshellarg(DB_NAME), escapeshellarg($temporaire)
    );
    $sortie = trim((string) shell_exec('bash -c ' . escapeshellarg($commande)));
    @unlink($options);

    if ($sortie !== '0') {
        $erreur = '';
        if (is_file($temporaire)) {
            $brut   = (string) shell_exec('gzip -dc ' . escapeshellarg($temporaire) . ' | head -c 2000');
            $erreur = preg_match('/mysqldump[^\n]*/', $brut, $m) ? $m[0] : trim(substr($brut, 0, 300));
            @unlink($temporaire);
        }
        throw new RuntimeException("mysqldump a échoué (code $sortie). $erreur");
    }

    // Contrôle : le fichier doit être lisible et se terminer par la ligne de fin de mysqldump
    $fin = (string) shell_exec('gzip -dc ' . escapeshellarg($temporaire) . ' | tail -n 3');
    if (!str_contains($fin, 'Dump completed')) {
        @unlink($temporaire);
        throw new RuntimeException('La sauvegarde est incomplète (ligne de fin absente).');
    }

    rename($temporaire, $cible);
    chmod($cible, 0600);
    return $cible;
}

// ---------- Sauvegarde des photos ----------
function sauvegarder_photos(string $dossier): ?string
{
    $source = realpath(GALERIE_DOSSIER);
    if (!$source || count(glob($source . '/*') ?: []) === 0) {
        return null;   // rien à sauvegarder
    }
    // Les photos de la carte des soins sont dans un dossier voisin
    $dossiers = [$source];
    $prestations = realpath(dirname($source) . '/prestations');
    if ($prestations) {
        $dossiers[] = $prestations;
    }

    $cible = $dossier . '/photos_' . date('Y-m-d') . '.tar.gz';
    $args  = '';
    foreach ($dossiers as $d) {
        $args .= ' -C ' . escapeshellarg(dirname($d)) . ' ' . escapeshellarg(basename($d));
    }
    exec('tar -czf ' . escapeshellarg($cible) . $args . ' 2>&1', $sortie, $code);
    if ($code !== 0) {
        @unlink($cible);
        throw new RuntimeException('Archive des photos impossible : ' . implode(' ', $sortie));
    }
    chmod($cible, 0600);
    return $cible;
}

// ---------- Rotation ----------
function faire_tourner(string $dossier): int
{
    $supprimes = 0;

    $bases = glob($dossier . '/base_*.sql.gz') ?: [];
    rsort($bases);                                   // les plus récentes d'abord (le nom contient la date)
    $gardees = array_slice($bases, 0, SAUVEGARDES_A_GARDER);
    $limiteMois = strtotime('-' . MOIS_A_GARDER . ' months');
    foreach (array_slice($bases, SAUVEGARDES_A_GARDER) as $f) {
        // On garde la première sauvegarde de chaque mois pendant 6 mois
        $premierDuMois = (bool) preg_match('/base_\d{4}-\d{2}-0[1]_/', basename($f));
        if ($premierDuMois && filemtime($f) > $limiteMois) {
            continue;
        }
        if (!in_array($f, $gardees, true) && @unlink($f)) {
            $supprimes++;
        }
    }

    $photos = glob($dossier . '/photos_*.tar.gz') ?: [];
    rsort($photos);
    foreach (array_slice($photos, ARCHIVES_PHOTOS) as $f) {
        if (@unlink($f)) {
            $supprimes++;
        }
    }
    return $supprimes;
}

// ---------- Alerte par email ----------
// L'alerte est technique : elle part uniquement vers le développeur (DEVELOPPEUR_EMAIL dans config.php),
// pas vers l'institut.
function alerter(string $probleme): void
{
    if (!defined('DEVELOPPEUR_EMAIL') || DEVELOPPEUR_EMAIL === '') {
        journal('Aucune adresse pour envoyer l\'alerte (renseigne DEVELOPPEUR_EMAIL dans config.php).');
        return;
    }
    envoyer_email(
        [['email' => DEVELOPPEUR_EMAIL]],
        '[' . APP_NAME . '] La sauvegarde de la base a échoué',
        gabarit_email('Sauvegarde en échec',
            '<p>La sauvegarde automatique n\'a pas pu se terminer :</p>'
            . '<p style="background:#faf6f8;border-left:3px solid #b3261e;padding:12px 16px;border-radius:6px;">' . e($probleme) . '</p>'
            . '<p>Les sauvegardes précédentes sont intactes. Vérifie la tâche planifiée et le fichier logs/sauvegarde.log sur le serveur.</p>',
            'Sauvegarde en échec')
    );
}

// ---------- Déroulement ----------
try {
    $dossier = dossier_sauvegardes();

    $fichier = sauvegarder_base($dossier);
    journal('Base sauvegardée : ' . basename($fichier) . ' (' . taille_lisible(filesize($fichier)) . ')');

    if (in_array('--photos', $argv, true) || date('w') === '0') {
        $archive = sauvegarder_photos($dossier);
        journal($archive ? 'Photos sauvegardées : ' . basename($archive) . ' (' . taille_lisible(filesize($archive)) . ')' : 'Photos : aucune à sauvegarder.');
    }

    $n = faire_tourner($dossier);
    if ($n > 0) {
        journal("$n ancienne(s) sauvegarde(s) supprimée(s).");
    }
    journal('Terminé. Dossier : ' . $dossier);
} catch (Throwable $e) {
    journal('ERREUR : ' . $e->getMessage());
    alerter($e->getMessage());
    exit(1);
}
