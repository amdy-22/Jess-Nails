<?php
require_once __DIR__ . '/../includes/auth.php';

if (is_logged_in()) {
    redirect_after_auth(BASE_URL . '/');
}

// Limite simple : 5 essais ratés, puis blocage 5 minutes (par session)
const MAX_ESSAIS     = 5;
const DUREE_BLOCAGE  = 300;

// Limite enregistrée en base (le visiteur ne peut pas la contourner en vidant ses cookies)
const LIMITE_FENETRE      = 900;   // 15 minutes
const LIMITE_PAR_EMAIL    = 8;     // échecs sur un même compte, d'où qu'ils viennent
const LIMITE_PAR_IP       = 20;    // échecs depuis une même connexion (large : plusieurs clientes peuvent partager une IP mobile)

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $bloqueJusqua = $_SESSION['bloque_jusqua'] ?? 0;
    if (time() < $bloqueJusqua) {
        $_SESSION['erreur_connexion'] = 'Trop de tentatives. Réessaie dans quelques minutes.';
        redirect(BASE_URL . '/connexion');
    }

    $email = $_POST['email'] ?? '';

    if (limite_atteinte('connexion_ip', ip_client(), LIMITE_PAR_IP, LIMITE_FENETRE)
        || limite_atteinte('connexion_email', $email, LIMITE_PAR_EMAIL, LIMITE_FENETRE)) {
        $_SESSION['erreur_connexion'] = 'Trop de tentatives. Réessaie dans 15 minutes ou utilise « Mot de passe oublié ».';
        redirect(BASE_URL . '/connexion');
    }

    $user  = attempt_login($email, $_POST['mot_de_passe'] ?? '');

    if (!$user) {
        limite_noter('connexion_ip', ip_client());
        limite_noter('connexion_email', $email);
        $_SESSION['essais'] = ($_SESSION['essais'] ?? 0) + 1;
        if ($_SESSION['essais'] >= MAX_ESSAIS) {
            $_SESSION['bloque_jusqua'] = time() + DUREE_BLOCAGE;
            $_SESSION['essais'] = 0;
        }
        set_old(['email' => $email]);
        $_SESSION['erreur_connexion'] = 'Email ou mot de passe incorrect.';
        redirect(BASE_URL . '/connexion');
    }

    unset($_SESSION['essais'], $_SESSION['bloque_jusqua']);
    limite_effacer('connexion_email', $email);
    clear_old();
    login_user($user);
    if ($user['role'] === 'admin') {
        journal_enregistrer($user, 'connexion', 'connexion', 'succes', 'Connexion à l\'administration.');
    }
    flash('succes', 'Bonjour ' . $user['prenom'] . ' !');

    $parDefaut = $user['role'] === 'admin' ? ADMIN_URL . '/' : BASE_URL . '/';
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
            <a href="<?= BASE_URL ?>/forgot_password" class="lien-oubli">Mot de passe oublié ?</a>
        </div>

        <button type="submit" class="btn btn-plein">Se connecter</button>
    </form>

    <p class="bas-formulaire">
        Pas encore de compte ? <a href="<?= BASE_URL ?>/inscription">Créer un compte</a>
    </p>
</section>

<?php
clear_old();
require __DIR__ . '/../includes/footer.php';
