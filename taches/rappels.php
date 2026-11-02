<?php
/**
 * Envoie les rappels par email avant les rendez-vous.
 *
 * Tâche planifiée (AlwaysData) toutes les 15 minutes :
 *   php $HOME/www/jess-nails/taches/rappels.php
 *
 * À la main, pour tester :
 *   php taches/rappels.php            envoie les rappels dus
 *   php taches/rappels.php --essai    affiche ce qui serait envoyé, sans rien envoyer
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Ce script se lance uniquement en ligne de commande.');
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/reservation.php';
require_once __DIR__ . '/../includes/notifications.php';

$essai = in_array('--essai', $argv, true);

// Un seul passage à la fois (si un passage est encore en cours, on ne relance pas)
$verrou = fopen(sys_get_temp_dir() . '/jessnails_rappels.lock', 'c');
if (!$verrou || !flock($verrou, LOCK_EX | LOCK_NB)) {
    echo date('Y-m-d H:i:s') . " Un passage est déjà en cours, on arrête.\n";
    exit(0);
}

function journal(string $message): void
{
    $ligne = date('Y-m-d H:i:s') . ' ' . $message . "\n";
    echo $ligne;
    @file_put_contents(__DIR__ . '/../logs/rappels.log', $ligne, FILE_APPEND);
}

// Rendez-vous confirmés, dans les 6 prochaines heures, rappel pas encore envoyé,
// réservés plus de 6 h à l'avance, cliente avec un email
$stmt = db()->prepare(
    "SELECT r.id, r.debut, u.email
     FROM reservations r
     JOIN utilisateurs u ON u.id = r.client_id
     WHERE r.statut = 'confirmee'
       AND r.rappel_envoye_le IS NULL
       AND r.debut > NOW()
       AND r.debut <= NOW() + INTERVAL ? HOUR
       AND r.created_at < r.debut - INTERVAL ? HOUR
       AND u.email IS NOT NULL AND u.email <> ''
     ORDER BY r.debut"
);
$stmt->execute([RAPPEL_HEURES_AVANT, RAPPEL_HEURES_AVANT]);
$aEnvoyer = $stmt->fetchAll();

if (!$aEnvoyer) {
    journal('Aucun rappel à envoyer.');
    exit(0);
}

$envoyes = $echecs = 0;
foreach ($aEnvoyer as $r) {
    if ($essai) {
        journal("[essai] Rappel à envoyer : réservation #{$r['id']} ({$r['debut']}) -> {$r['email']}");
        continue;
    }

    // On "réserve" l'envoi avant d'envoyer : jamais deux rappels pour le même rendez-vous
    $prise = db()->prepare('UPDATE reservations SET rappel_envoye_le = NOW() WHERE id = ? AND rappel_envoye_le IS NULL');
    $prise->execute([$r['id']]);
    if ($prise->rowCount() !== 1) {
        continue;
    }

    try {
        $ok = notifier_rappel((int) $r['id']);
    } catch (Throwable $e) {
        $ok = false;
        error_log('[Rappels] ' . $e->getMessage());
    }

    if ($ok) {
        $envoyes++;
        journal("Rappel envoyé : réservation #{$r['id']} ({$r['debut']}) -> {$r['email']}");
    } else {
        // Échec (Brevo indisponible, clé refusée...) : on réessaiera au prochain passage
        db()->prepare('UPDATE reservations SET rappel_envoye_le = NULL WHERE id = ?')->execute([$r['id']]);
        $echecs++;
        journal("ÉCHEC du rappel : réservation #{$r['id']} -> {$r['email']} (nouvel essai au prochain passage)");
    }
}

if (!$essai) {
    journal("Terminé : $envoyes envoyé(s), $echecs échec(s).");
}

// Code de sortie 1 en cas d'échec : AlwaysData envoie alors un rapport d'erreur par email
exit($echecs > 0 ? 1 : 0);
