<?php
/**
 * Fonctions utilitaires communes.
 */

/** Échappe une valeur pour l'affichage HTML (protection XSS). */
function e(?string $valeur): string
{
    return htmlspecialchars($valeur ?? '', ENT_QUOTES, 'UTF-8');
}

/** Redirige vers une URL et arrête le script. */
function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

/** Formate un montant : 15000 -> "15 000 FCFA". */
function format_fcfa(int $montant): string
{
    return number_format($montant, 0, ',', ' ') . ' FCFA';
}

/** Formate une durée : 90 -> "1 h 30". */
function format_duree(int $minutes): string
{
    if ($minutes < 60) {
        return $minutes . ' min';
    }
    $h = intdiv($minutes, 60);
    $m = $minutes % 60;
    return $h . ' h' . ($m ? ' ' . str_pad((string) $m, 2, '0', STR_PAD_LEFT) : '');
}

// ---------- Messages flash (affichés une seule fois) ----------

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function get_flashes(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $messages;
}

// ---------- Protection CSRF des formulaires ----------

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Champ caché à placer dans chaque <form method="post">. */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/** À appeler au début du traitement de chaque POST. */
function csrf_verify(): void
{
    $envoye = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $envoye)) {
        http_response_code(419);
        die('Session expirée. Recharge la page et réessaie.');
    }
}

// ---------- Ré-affichage des champs après une erreur ----------

function old(string $champ): string
{
    return e($_SESSION['old'][$champ] ?? '');
}

function set_old(array $donnees): void
{
    unset($donnees['mot_de_passe'], $donnees['mot_de_passe_confirmation'], $donnees['csrf_token']);
    $_SESSION['old'] = $donnees;
}

function clear_old(): void
{
    unset($_SESSION['old']);
}

/**
 * Horaires résumés pour le pied de page, lus en base.
 * Ex. ["Tous les jours, de 9 h à 19 h"] ou ["Lun. à ven. : 9 h à 19 h", "Sam. : 9 h à 14 h", "Dim. : fermé"]
 */
function resume_horaires(): array
{
    static $resume = null;
    if ($resume !== null) {
        return $resume;
    }
    try {
        $lignes = db()->query('SELECT * FROM horaires ORDER BY jour_semaine')->fetchAll();
    } catch (Throwable $e) {
        return $resume = [];
    }
    $heure = fn(string $t) => (int) substr($t, 0, 2) . ' h' . (substr($t, 3, 2) !== '00' ? substr($t, 3, 2) : '');
    $noms  = [1 => 'Lun.', 'Mar.', 'Mer.', 'Jeu.', 'Ven.', 'Sam.', 'Dim.'];

    $parJour = [];
    foreach ($lignes as $l) {
        $parJour[(int) $l['jour_semaine']] = $l['heure_ouverture'] === null
            ? 'fermé'
            : $heure($l['heure_ouverture']) . ' à ' . $heure($l['heure_fermeture']);
    }
    if (count($parJour) === 7 && count(array_unique($parJour)) === 1 && reset($parJour) !== 'fermé') {
        return $resume = ['Tous les jours, de ' . reset($parJour)];
    }

    // Regroupe les jours consécutifs qui ont les mêmes horaires
    $resume = [];
    $debut  = null;
    for ($j = 1; $j <= 8; $j++) {
        $txt = $parJour[$j] ?? null;
        if ($debut !== null && ($j === 8 || $txt !== $parJour[$debut])) {
            $fin = $j - 1;
            $resume[] = ($debut === $fin ? $noms[$debut] : $noms[$debut] . ' à ' . mb_strtolower($noms[$fin])) . ' : ' . $parJour[$debut];
            $debut = null;
        }
        if ($debut === null && $txt !== null) {
            $debut = $j;
        }
    }
    return $resume;
}