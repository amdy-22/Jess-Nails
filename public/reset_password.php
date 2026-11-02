<?php
/**
 * Choix d'un nouveau mot de passe depuis le lien reçu par email.
 */
require_once __DIR__ . '/../includes/auth.php';

$jeton = (string) ($_POST['token'] ?? $_GET['token'] ?? '');

/** Demande de réinitialisation valide pour ce jeton, ou null. */
function demande_valide(string $jeton): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $jeton)) {
        return null;
    }
    $stmt = db()->prepare(
        'SELECT rm.*, u.prenom, u.email FROM reinitialisations_mdp rm
         JOIN utilisateurs u ON u.id = rm.utilisateur_id
         WHERE rm.token_hash = ? AND rm.utilise_le IS NULL AND rm.expire_le > NOW()'
    );
    $stmt->execute([hash('sha256', $jeton)]);
    return $stmt->fetch() ?: null;
}

$demande = demande_valide($jeton);
$erreurs = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $demande) {
    csrf_verify();
    $mdp  = $_POST['mot_de_passe'] ?? '';
    $mdp2 = $_POST['mot_de_passe_confirmation'] ?? '';

    if (strlen($mdp) < 8) {
        $erreurs[] = 'Le mot de passe doit faire au moins 8 caractères.';
    } elseif ($mdp !== $mdp2) {
        $erreurs[] = 'Les deux mots de passe ne correspondent pas.';
    }

    if (!$erreurs) {
        $pdo = db();
        $pdo->beginTransaction();
        // Le lien ne sert qu'une fois (même si deux onglets envoient le formulaire en même temps)
        $marque = $pdo->prepare('UPDATE reinitialisations_mdp SET utilise_le = NOW() WHERE id = ? AND utilise_le IS NULL');
        $marque->execute([$demande['id']]);

        if ($marque->rowCount() === 1) {
            $pdo->prepare('UPDATE utilisateurs SET mot_de_passe = ? WHERE id = ?')
                ->execute([password_hash($mdp, PASSWORD_DEFAULT), $demande['utilisateur_id']]);
            $pdo->prepare('DELETE FROM reinitialisations_mdp WHERE utilisateur_id = ? AND utilise_le IS NULL')
                ->execute([$demande['utilisateur_id']]);
            $pdo->commit();

            unset($_SESSION['essais'], $_SESSION['bloque_jusqua']);   // débloque la connexion
            flash('succes', 'Ton mot de passe est modifié. Tu peux te connecter.');
            redirect(BASE_URL . '/connexion.php');
        }
        $pdo->rollBack();
        $demande = null;   // déjà utilisé entre-temps
    }
}

$pageTitle = 'Nouveau mot de passe';
require __DIR__ . '/../includes/header.php';
?>

<section class="carte-formulaire">
    <?php if (!$demande): ?>
        <h1>Lien expiré ou déjà utilisé</h1>
        <p>Ce lien n'est plus valable. Il expire au bout d'1 heure et ne fonctionne qu'une seule fois.</p>
        <a href="<?= BASE_URL ?>/forgot_password.php" class="btn btn-plein">Demander un nouveau lien</a>
    <?php else: ?>
        <h1>Nouveau mot de passe</h1>
        <p class="discret">Pour le compte <strong><?= e($demande['email']) ?></strong></p>

        <?php if ($erreurs): ?>
            <div class="alerte alerte-erreur" role="alert"><ul><?php foreach ($erreurs as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
        <?php endif; ?>

        <form method="post" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="token" value="<?= e($jeton) ?>">
            <div class="champ">
                <label for="mot_de_passe">Nouveau mot de passe</label>
                <input type="password" id="mot_de_passe" name="mot_de_passe" minlength="8" autocomplete="new-password" required autofocus>
            </div>
            <div class="champ">
                <label for="mot_de_passe_confirmation">Confirmation</label>
                <input type="password" id="mot_de_passe_confirmation" name="mot_de_passe_confirmation" minlength="8" autocomplete="new-password" required>
            </div>
            <button type="submit" class="btn btn-plein">Enregistrer</button>
        </form>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/../includes/footer.php';