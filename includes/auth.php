<?php
/**
 * Point d'entrée commun : chaque page commence par
 *   require_once __DIR__ . '/../includes/auth.php';
 * Il charge la config, la base, les fonctions et démarre la session.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/limites.php';

// ---------- Session sécurisée ----------
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => APP_ENV === 'prod',   // HTTPS obligatoire en production
        'httponly' => true,                 // cookie inaccessible au JavaScript
        'samesite' => 'Lax',
    ]);
    session_name('BEAUTYSESSID');
    session_start();
}

// ---------- Connexion / déconnexion ----------

/**
 * Vérifie email + mot de passe. Renvoie l'utilisateur ou null.
 */
function attempt_login(string $email, string $motDePasse): ?array
{
    $stmt = db()->prepare('SELECT * FROM utilisateurs WHERE email = ? LIMIT 1');
    $stmt->execute([mb_strtolower(trim($email))]);
    $user = $stmt->fetch();

    // Compte créé par l'institut sans mot de passe : connexion impossible
    // tant que la cliente n'a pas choisi son mot de passe (via "Mot de passe oublié")
    if (!$user || empty($user['mot_de_passe']) || !password_verify($motDePasse, $user['mot_de_passe'])) {
        return null;
    }

    // Met à jour le hachage si l'algorithme par défaut de PHP a évolué
    if (password_needs_rehash($user['mot_de_passe'], PASSWORD_DEFAULT)) {
        $upd = db()->prepare('UPDATE utilisateurs SET mot_de_passe = ? WHERE id = ?');
        $upd->execute([password_hash($motDePasse, PASSWORD_DEFAULT), $user['id']]);
    }

    return $user;
}

function login_user(array $user): void
{
    session_regenerate_id(true);           // évite la fixation de session
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['role']    = $user['role'];
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

// ---------- Utilisateur courant ----------

function current_user(): ?array
{
    static $user = false;

    if ($user === false) {
        $user = null;
        if (!empty($_SESSION['user_id'])) {
            $stmt = db()->prepare(
                'SELECT id, nom, prenom, email, telephone, role FROM utilisateurs WHERE id = ?'
            );
            $stmt->execute([$_SESSION['user_id']]);
            $user = $stmt->fetch() ?: null;
        }
    }
    return $user;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function is_admin(): bool
{
    return (current_user()['role'] ?? null) === 'admin';
}

// ---------- Protection des pages ----------

/** Page réservée aux personnes connectées. */
function require_login(): void
{
    if (!is_logged_in()) {
        $_SESSION['apres_connexion'] = $_SERVER['REQUEST_URI'] ?? null;
        flash('info', 'Pour réserver, connecte-toi ou crée ton compte en une minute.');
        redirect(BASE_URL . '/connexion');
    }
}

/**
 * Après connexion ou inscription : renvoie vers la page mémorisée
 * (ex. la réservation en cours), sinon vers la page par défaut.
 */
function redirect_after_auth(string $parDefaut): never
{
    $cible = $_SESSION['apres_connexion'] ?? null;
    unset($_SESSION['apres_connexion']);

    // On n'accepte qu'un chemin interne (/beauty/...), jamais une URL externe
    if (is_string($cible) && str_starts_with($cible, '/') && !str_starts_with($cible, '//')) {
        redirect($cible);
    }
    redirect($parDefaut);
}

/** Page réservée aux administrateurs. */
function require_admin(): void
{
    require_login();
    if (!is_admin()) {
        http_response_code(403);
        $pageTitle = 'Accès refusé';
        require __DIR__ . '/header.php';
        ?>
        <section class="carte-formulaire">
            <h1>Accès réservé à l'administration</h1>
            <p>Tu es connecté avec le compte <strong><?= e(current_user()['email']) ?></strong>, qui n'est pas un compte administrateur.</p>
            <form action="<?= BASE_URL ?>/deconnexion" method="post">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-plein btn-large">Me connecter avec un autre compte</button>
            </form>
            <p class="bas-formulaire"><a href="<?= BASE_URL ?>/">Retour au site</a></p>
        </section>
        <?php
        require __DIR__ . '/footer.php';
        exit;
    }
}
