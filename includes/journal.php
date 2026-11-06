<?php
/**
 * Journal d'activité de l'administration.
 *
 * Toute requête POST faite depuis une page admin est enregistrée automatiquement (qui, quand, quelle section,
 * quelle action, résultat, valeurs envoyées). Le branchement est dans require_admin() : une nouvelle page admin
 * est donc journalisée sans rien ajouter. La description reprend le message affiché à l'administratrice
 * (« Message supprimé. »), complétée par la cible de l'action (« Awa D., Manucure simple, 12 nov. »).
 *
 * Journal TECHNIQUE, réservé au développeur : une ligne par action dans logs/admin.log (sur le serveur,
 * dossier fermé au web et non versionné). Rien n'est affiché dans l'administration.
 * Consultation : tail -n 50 ~/www/jess-nails/logs/admin.log
 * Tolérant : si le fichier n'est pas inscriptible, rien n'est enregistré et le site continue de fonctionner.
 */

const JOURNAL_FICHIER    = __DIR__ . '/../logs/admin.log';
const JOURNAL_TAILLE_MAX = 2 * 1024 * 1024;   // au-delà : rotation (admin.log -> admin.log.1)


/** Écrit une ligne dans logs/admin.log. Ne lève jamais d'exception. */
function journal_enregistrer(?array $admin, string $page, string $action, string $resultat, string $description, array $details = []): void
{
    try {
        $dossier = dirname(JOURNAL_FICHIER);
        if (!is_dir($dossier)) {
            @mkdir($dossier, 0775, true);
            @file_put_contents($dossier . '/.htaccess', "Require all denied\n");
        }
        if (is_file(JOURNAL_FICHIER) && filesize(JOURNAL_FICHIER) > JOURNAL_TAILLE_MAX) {
            @rename(JOURNAL_FICHIER, JOURNAL_FICHIER . '.1');
        }
        $nom = trim(($admin['prenom'] ?? '') . ' ' . ($admin['nom'] ?? '')) ?: 'inconnu';
        $nettoyer = fn(string $t) => trim(preg_replace('/\s+/', ' ', $t));   // une action = une ligne
        $ligne = implode(' | ', [
            date('Y-m-d H:i:s'),
            $nettoyer($nom) . ' (#' . ($admin['id'] ?? '?') . ')',
            ip_client(),
            $page,
            $action,
            $resultat === 'echec' ? 'ECHEC' : 'OK',
            $nettoyer($description),
            $details ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '-',
        ]) . "\n";
        @file_put_contents(JOURNAL_FICHIER, $ligne, FILE_APPEND | LOCK_EX);
    } catch (Throwable $e) {
        // on ne bloque jamais l'action de l'administratrice
    }
}

/** Valeurs envoyées, sans secrets et raccourcies. */
function journal_valeurs_envoyees(array $post): array
{
    $sortie = [];
    // Sécurité : les formulaires de mot de passe et de changement d'email n'envoient que des secrets, on ne garde rien
    if (in_array($post['action'] ?? '', ['mot_de_passe', 'email'], true)) {
        return [];
    }
    foreach ($post as $cle => $valeur) {
        if (preg_match('/csrf|token|mot_de_passe|password|passe|mdp|pwd|secret|actuel|nouveau|confirmation|^retour$/i', (string) $cle)) {
            continue;
        }
        if (is_array($valeur)) {
            $valeur = json_encode($valeur, JSON_UNESCAPED_UNICODE);
        }
        $sortie[(string) $cle] = mb_substr((string) $valeur, 0, 150);
    }
    return $sortie;
}

/** Nom lisible de ce que l'action va toucher, lu AVANT l'action (après une suppression il serait introuvable). */
function journal_cible(string $page, array $post): string
{
    $id = (int) ($post['reservation'] ?? $post['rappel_whatsapp'] ?? $post['id'] ?? 0);
    if ($id <= 0) {
        return '';
    }
    $requetes = [
        'reservations'         => "SELECT CONCAT(u.prenom, ' ', u.nom, ', ', COALESCE(r.libelle, p.nom), ', ', DATE_FORMAT(r.debut, '%d/%m/%Y %Hh%i'))
                                   FROM reservations r JOIN utilisateurs u ON u.id = r.client_id JOIN prestations p ON p.id = r.prestation_id WHERE r.id = ?",
        'prestations'          => 'SELECT nom FROM prestations WHERE id = ?',
        'praticiennes'         => 'SELECT nom FROM praticiennes WHERE id = ?',
        'planning-praticienne' => 'SELECT nom FROM praticiennes WHERE id = ?',
        'galerie'              => "SELECT COALESCE(NULLIF(legende, ''), CONCAT('photo n° ', id)) FROM photos WHERE id = ?",
        'messages'             => "SELECT CONCAT(nom, ' : ', sujet) FROM messages_contact WHERE id = ?",
        'avis'                 => "SELECT CONCAT(u.prenom, ' ', u.nom, ', ', a.note, '/5') FROM avis a JOIN utilisateurs u ON u.id = a.client_id WHERE a.id = ?",
    ];
    $requetes['index']    = $requetes['reservations'];
    $requetes['deplacer'] = $requetes['reservations'];
    if (!isset($requetes[$page])) {
        return '';
    }
    try {
        $stmt = db()->prepare($requetes[$page]);
        $stmt->execute([$id]);
        return (string) ($stmt->fetchColumn() ?: '');
    } catch (Throwable $e) {
        return '';
    }
}

/** Appelée par require_admin() : prépare l'enregistrement de la requête POST en cours. */
function journal_demarrer(): void
{
    static $demarre = false;
    if ($demarre || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        return;
    }
    $demarre = true;

    $admin   = current_user();
    $page    = basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? $_SERVER['SCRIPT_NAME'] ?? ''), '.php');
    $post    = $_POST;
    $action  = (string) ($post['action'] ?? $post['action_resa'] ?? (isset($post['rappel_whatsapp']) ? 'rappel_whatsapp' : '') ?: '-');
    $cible   = journal_cible($page, $post);
    $deja    = count($_SESSION['flash'] ?? []);

    register_shutdown_function(function () use ($admin, $page, $post, $action, $cible, $deja) {
        $flashs   = array_slice($_SESSION['flash'] ?? [], $deja);
        $messages = array_map(fn($f) => (string) $f['message'], $flashs);
        $succes   = array_filter($flashs, fn($f) => $f['type'] !== 'erreur');

        $echec = http_response_code() >= 400 || ($flashs && !$succes);
        $description = $messages ? implode(' ', $messages) : 'Action « ' . $action . ' » effectuée.';
        if ($cible !== '') {
            $description .= ' [' . $cible . ']';
        }
        journal_enregistrer($admin, $page, $action, $echec ? 'echec' : 'succes', $description, journal_valeurs_envoyees($post));
    });
}
