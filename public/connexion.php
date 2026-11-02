<?php
require_once __DIR__ . '/../includes/auth.php';

if (is_logged_in()) {
    redirect_after_auth(BASE_URL . '/index.php');
}

// Limite simple : 5 essais ratés, puis blocage 5 minutes (par session)
const MAX_ESSAIS     = 5;
const DUREE_BLOCAGE  = 300;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $bloqueJusqua = $_SESSION['bloque_jusqua'] ?? 0;
    if (time() < $bloqueJusqua) {
        $_SESSION['erreur_connexion'] = 'Trop de tentatives. Réessaie dans quelques minutes.';
        redirect(BASE_URL . '/connexion.php');
    }

    $email = $_POST['email'] ?? '';
    $user  = attempt_login($email, $_POST['mot_de_passe'] ?? '');

    if (!$user) {
        $_SESSION['essais'] = ($_SESSION['essais'] ?? 0) + 1;
        if ($_SESSION['essais'] >= MAX_ESSAIS) {
            $_SESSION['bloque_jusqua'] = time() + DUREE_BLOCAGE;
            $_SESSION['essais'] = 0;
        }
        set_old(['email' => $email]);
        $_SESSION['erreur_connexion'] = 'Email ou mot de passe incorrect.';
        redirect(BASE_URL . '/connexion.php');
    }

    unset($_SESSION['essais'], $_SESSION['bloque_jusqua']);
    clear_old();
    login_user($user);
    flash('succes', 'Bonjour ' . $user['prenom'] . ' !');

    $parDefaut = $user['role'] === 'admin' ? ADMIN_URL . '/index.php' : BASE_URL . '/index.php';
    redirect_after_auth($parDefaut);
}

$erreur = $_SESSION['erreur_connexion'] ?? null;
unset($_SESSION['erreur_connexion']);

$pageTitle  = 'Connexion';
$activePage = 'connexion';
require __DIR__ . '/../includes/header.php';
?>

<section class="carte-formulaire">
    <h1>Connexion</h1>

    <?php if ($erreur): ?>
        <div class="alerte alerte-erreur" role="alert"><?= e($erreur) ?></div>
    <?php endif; ?>

    <form method="post" novalidate>
        <?= csrf_field() ?>

        <div class="champ">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" value="<?= old('email') ?>" autocomplete="email" required>
        </div>

        <div class="champ">
            <label for="mot_de_passe">Mot de passe</label>
            <input type="password" id="mot_de_passe" name="mot_de_passe" autocomplete="current-password" required>
            <a href="<?= BASE_URL ?>/forgot_password.php" class="lien-oubli">Mot de passe oublié ?</a>
        </div>

        <button type="submit" class="btn btn-plein">Se connecter</button>
    </form>

    <p class="bas-formulaire">
        Pas encore de compte ? <a href="<?= BASE_URL ?>/inscription.php">Créer un compte</a>
    </p>
</section>

<?php
clear_old();
require __DIR__ . '/../includes/footer.php';