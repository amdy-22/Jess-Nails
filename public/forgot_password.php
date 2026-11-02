<?php
/**
 * Demande de réinitialisation du mot de passe.
 * Le message affiché est toujours le même, que l'email existe ou non,
 * pour ne pas révéler qui a un compte.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mailer.php';

const REINIT_VALIDITE_MIN = 60;   // le lien expire au bout d'1 h
const REINIT_DELAI_MIN    = 2;    // pas plus d'un email toutes les 2 min pour un même compte
const REINIT_MAX_SESSION  = 5;    // 5 demandes max par session et par quart d'heure

if (is_logged_in()) {
    redirect(BASE_URL . '/profile.php#profil');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $email = mb_strtolower(trim($_POST['email'] ?? ''));

    // Limite par session (anti-abus)
    $demandes = array_filter($_SESSION['demandes_reinit'] ?? [], fn($t) => $t > time() - 900);
    if (count($demandes) >= REINIT_MAX_SESSION) {
        flash('erreur', 'Trop de demandes. Réessaie dans quelques minutes.');
        redirect(BASE_URL . '/forgot_password.php');
    }
    $demandes[] = time();
    $_SESSION['demandes_reinit'] = $demandes;

    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $stmt = db()->prepare('SELECT id, prenom, email FROM utilisateurs WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            // Un email a-t-il déjà été envoyé il y a moins de 2 min ?
            $recent = db()->prepare(
                'SELECT COUNT(*) FROM reinitialisations_mdp
                 WHERE utilisateur_id = ? AND created_at > NOW() - INTERVAL ' . REINIT_DELAI_MIN . ' MINUTE'
            );
            $recent->execute([$user['id']]);

            if ((int) $recent->fetchColumn() === 0) {
                // Les anciens liens non utilisés ne servent plus
                db()->prepare('DELETE FROM reinitialisations_mdp WHERE utilisateur_id = ? AND utilise_le IS NULL')
                    ->execute([$user['id']]);

                $jeton = bin2hex(random_bytes(32));
                db()->prepare(
                    'INSERT INTO reinitialisations_mdp (utilisateur_id, token_hash, expire_le)
                     VALUES (?, ?, NOW() + INTERVAL ' . REINIT_VALIDITE_MIN . ' MINUTE)'
                )->execute([$user['id'], hash('sha256', $jeton)]);

                $lien = BASE_URL . '/reset_password.php?token=' . $jeton;
                $contenu = '<p>Bonjour ' . e($user['prenom']) . ',</p>'
                    . '<p>Tu as demandé à changer ton mot de passe. Clique sur le bouton ci-dessous pour en choisir un nouveau :</p>'
                    . '<p style="margin:24px 0;"><a href="' . e($lien) . '" style="background:#8c3a5b;color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:999px;display:inline-block;">Choisir un nouveau mot de passe</a></p>'
                    . '<p style="color:#6e5f6c;font-size:13px;">Ce lien est valable 1 heure et ne fonctionne qu\'une fois. '
                    . 'Si tu n\'es pas à l\'origine de cette demande, ignore cet email : ton mot de passe actuel reste valable.</p>';

                envoyer_email(
                    [['email' => $user['email'], 'name' => $user['prenom']]],
                    'Réinitialisation de ton mot de passe ' . APP_NAME,
                    gabarit_email('Nouveau mot de passe', $contenu)
                );
            }
        }
    }

    flash('succes', 'Si un compte existe avec cette adresse, un email avec un lien vient de lui être envoyé. Pense à vérifier tes spams.');
    redirect(BASE_URL . '/connexion.php');
}

$pageTitle = 'Mot de passe oublié';
require __DIR__ . '/../includes/header.php';
?>

<section class="carte-formulaire">
    <h1>Mot de passe oublié</h1>
    <p class="discret">Indique l'email de ton compte : on t'envoie un lien pour choisir un nouveau mot de passe.</p>

    <form method="post" novalidate>
        <?= csrf_field() ?>
        <div class="champ">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" autocomplete="email" required autofocus>
        </div>
        <button type="submit" class="btn btn-plein">Recevoir le lien</button>
    </form>

    <p class="bas-formulaire"><a href="<?= BASE_URL ?>/connexion.php">Retour à la connexion</a></p>
</section>

<?php require __DIR__ . '/../includes/footer.php';