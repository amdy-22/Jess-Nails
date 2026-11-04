<?php
require_once __DIR__ . '/../includes/auth.php';

// Déjà connecté : pas besoin de s'inscrire
if (is_logged_in()) {
    redirect_after_auth(BASE_URL . '/');
}

/**
 * Normalise un numéro béninois : accepte "01 97 00 00 00", "+229 0197000000"...
 * Renvoie le format 01XXXXXXXX (10 chiffres) ou null s'il est invalide.
 */
function normaliser_telephone(string $tel): ?string
{
    $tel = preg_replace('/[\s.\-]/', '', $tel);
    $tel = preg_replace('/^(\+229|00229)/', '', $tel);
    return preg_match('/^01\d{8}$/', $tel) ? $tel : null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $prenom    = trim($_POST['prenom'] ?? '');
    $nom       = trim($_POST['nom'] ?? '');
    $email     = mb_strtolower(trim($_POST['email'] ?? ''));
    $telephone = normaliser_telephone($_POST['telephone'] ?? '');
    $mdp       = $_POST['mot_de_passe'] ?? '';
    $mdp2      = $_POST['mot_de_passe_confirmation'] ?? '';

    $erreurs = [];
    if ($prenom === '' || $nom === '') {
        $erreurs[] = 'Indique ton prénom et ton nom.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $erreurs[] = 'L\'adresse email n\'est pas valide.';
    }
    if ($telephone === null) {
        $erreurs[] = 'Le numéro doit compter 10 chiffres et commencer par 01 (ex. 0197123456).';
    }
    if (strlen($mdp) < 8) {
        $erreurs[] = 'Le mot de passe doit faire au moins 8 caractères.';
    } elseif ($mdp !== $mdp2) {
        $erreurs[] = 'Les deux mots de passe ne correspondent pas.';
    }

    if (!$erreurs) {
        $existe = db()->prepare('SELECT mot_de_passe FROM utilisateurs WHERE email = ?');
        $existe->execute([$email]);
        $compte = $existe->fetch();
        if ($compte && empty($compte['mot_de_passe'])) {
            $erreurs[] = 'L\'institut a déjà créé un compte avec cet email. Clique sur « Mot de passe oublié » '
                       . 'depuis la page de connexion pour choisir ton mot de passe.';
        } elseif ($compte) {
            $erreurs[] = 'Un compte existe déjà avec cet email. Connecte-toi plutôt.';
        }
    }

    if ($erreurs) {
        set_old($_POST);
        $_SESSION['erreurs'] = $erreurs;
        redirect(BASE_URL . '/inscription');
    }

    $stmt = db()->prepare(
        'INSERT INTO utilisateurs (nom, prenom, email, telephone, mot_de_passe)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$nom, $prenom, $email, $telephone, password_hash($mdp, PASSWORD_DEFAULT)]);

    clear_old();
    login_user(['id' => db()->lastInsertId(), 'role' => 'client']);
    flash('succes', 'Bienvenue ' . $prenom . ', ton compte vient d\'être créé.');

    // Retour à la réservation en cours si le client venait de là
    redirect_after_auth(BASE_URL . '/');
}

$erreurs = $_SESSION['erreurs'] ?? [];
unset($_SESSION['erreurs']);

$pageTitle  = 'Créer un compte';
$activePage = 'inscription';
require __DIR__ . '/../includes/header.php';
?>

<section class="carte-formulaire">
    <h1>Créer un compte</h1>
    <p class="discret">Ton compte te permet de réserver et de retrouver tes rendez-vous.</p>

    <?php if ($erreurs): ?>
        <div class="alerte alerte-erreur" role="alert">
            <ul>
                <?php foreach ($erreurs as $err): ?>
                    <li><?= e($err) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="post" novalidate>
        <?= csrf_field() ?>

        <div class="ligne-2">
            <div class="champ">
                <label for="prenom">Prénom</label>
                <input type="text" id="prenom" name="prenom" value="<?= old('prenom') ?>" autocomplete="given-name" required>
            </div>
            <div class="champ">
                <label for="nom">Nom</label>
                <input type="text" id="nom" name="nom" value="<?= old('nom') ?>" autocomplete="family-name" required>
            </div>
        </div>

        <div class="champ">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" value="<?= old('email') ?>" autocomplete="email" required>
        </div>

        <div class="champ">
            <label for="telephone">Téléphone (WhatsApp)</label>
            <input type="tel" id="telephone" name="telephone" value="<?= old('telephone') ?>"
                   placeholder="0197123456" autocomplete="tel" required>
        </div>

        <div class="ligne-2">
            <div class="champ">
                <label for="mot_de_passe">Mot de passe</label>
                <input type="password" id="mot_de_passe" name="mot_de_passe" minlength="8" autocomplete="new-password" required>
            </div>
            <div class="champ">
                <label for="mot_de_passe_confirmation">Confirmation</label>
                <input type="password" id="mot_de_passe_confirmation" name="mot_de_passe_confirmation" minlength="8" autocomplete="new-password" required>
            </div>
        </div>
        <p class="discret petit-texte">En créant ton compte, tu acceptes notre <a href="<?= BASE_URL ?>/confidentialite" target="_blank">politique de confidentialité</a>.</p>
        <button type="submit" class="btn btn-plein">Créer mon compte</button>
    </form>

    <p class="bas-formulaire">Déjà un compte ? <a href="<?= BASE_URL ?>/connexion">Se connecter</a></p>
</section>

<?php
clear_old();
require __DIR__ . '/../includes/footer.php';