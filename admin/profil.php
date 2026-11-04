<?php
/**
 * Profil de l'administratrice connectée : informations, email de connexion, mot de passe.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/reservation.php';   // format_date_fr()
require_admin();

$urlPage = ADMIN_URL . '/profil';
$moi = current_user();

/** Vérifie le mot de passe actuel de l'admin connectée. */
function mot_de_passe_correct(int $id, string $saisi): bool
{
    $stmt = db()->prepare('SELECT mot_de_passe FROM utilisateurs WHERE id = ?');
    $stmt->execute([$id]);
    $hash = (string) $stmt->fetchColumn();
    return $hash !== '' && password_verify($saisi, $hash);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    // ---------- Informations ----------
    if ($action === 'infos') {
        $prenom = mb_substr(trim($_POST['prenom'] ?? ''), 0, 80);
        $nom    = mb_substr(trim($_POST['nom'] ?? ''), 0, 80);
        $tel    = preg_replace('/^(\+229|00229)/', '', preg_replace('/[\s.\-()]/', '', $_POST['telephone'] ?? ''));

        if ($prenom === '' || $nom === '') {
            flash('erreur', 'Le prénom et le nom sont obligatoires.');
        } elseif ($tel !== '' && !preg_match('/^01\d{8}$/', $tel)) {
            flash('erreur', 'Le numéro doit compter 10 chiffres et commencer par 01 (ou rester vide).');
        } else {
            db()->prepare('UPDATE utilisateurs SET prenom = ?, nom = ?, telephone = ? WHERE id = ?')
                ->execute([$prenom, $nom, $tel !== '' ? $tel : null, $moi['id']]);
            flash('succes', 'Tes informations sont à jour.');
        }
        redirect($urlPage);
    }

    // ---------- Email de connexion (mot de passe demandé) ----------
    if ($action === 'email') {
        $email  = mb_strtolower(trim($_POST['email'] ?? ''));
        $actuel = $_POST['mot_de_passe_email'] ?? '';

        $existe = db()->prepare('SELECT COUNT(*) FROM utilisateurs WHERE email = ? AND id <> ?');
        $existe->execute([$email, $moi['id']]);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('erreur', 'L\'adresse email n\'est pas valide.');
        } elseif ($email === mb_strtolower((string) $moi['email'])) {
            flash('info', 'C\'est déjà ton adresse actuelle.');
        } elseif ((int) $existe->fetchColumn() > 0) {
            flash('erreur', 'Cette adresse est déjà utilisée par un autre compte.');
        } elseif (!mot_de_passe_correct((int) $moi['id'], $actuel)) {
            flash('erreur', 'Mot de passe incorrect : l\'adresse n\'a pas été changée.');
        } else {
            db()->prepare('UPDATE utilisateurs SET email = ? WHERE id = ?')->execute([$email, $moi['id']]);
            flash('succes', 'Adresse de connexion modifiée : utilise désormais ' . $email . ' pour te connecter.');
        }
        redirect($urlPage . '#email');
    }

    // ---------- Mot de passe ----------
    if ($action === 'mot_de_passe') {
        $actuel  = $_POST['actuel'] ?? '';
        $nouveau = $_POST['nouveau'] ?? '';
        $confirm = $_POST['confirmation'] ?? '';

        if (!mot_de_passe_correct((int) $moi['id'], $actuel)) {
            flash('erreur', 'Le mot de passe actuel est incorrect.');
        } elseif (strlen($nouveau) < 10) {
            flash('erreur', 'Pour un compte administrateur, le nouveau mot de passe doit faire au moins 10 caractères.');
        } elseif ($nouveau !== $confirm) {
            flash('erreur', 'Les deux nouveaux mots de passe ne correspondent pas.');
        } elseif ($nouveau === $actuel) {
            flash('erreur', 'Le nouveau mot de passe doit être différent de l\'actuel.');
        } else {
            db()->prepare('UPDATE utilisateurs SET mot_de_passe = ? WHERE id = ?')
                ->execute([password_hash($nouveau, PASSWORD_DEFAULT), $moi['id']]);
            session_regenerate_id(true);
            flash('succes', 'Mot de passe modifié.');
        }
        redirect($urlPage . '#mot-de-passe');
    }

    redirect($urlPage);
}

// Infos complémentaires
$stmt = db()->prepare('SELECT created_at FROM utilisateurs WHERE id = ?');
$stmt->execute([$moi['id']]);
$depuis = new DateTimeImmutable((string) $stmt->fetchColumn());
$nbAdmins = (int) db()->query("SELECT COUNT(*) FROM utilisateurs WHERE role = 'admin'")->fetchColumn();
$notifVers = ADMIN_NOTIFICATION_EMAIL !== '' ? ADMIN_NOTIFICATION_EMAIL : (string) $moi['email'];

$pageTitle   = 'Mon profil';
$activeAdmin = 'profil';
require __DIR__ . '/../includes/admin_header.php';
?>

<section class="profil-carte">
    <span class="avatar avatar-grand" aria-hidden="true"><?= e(initiales($moi)) ?></span>
    <div>
        <h1><?= e($moi['prenom'] . ' ' . $moi['nom']) ?></h1>
        <p class="discret">
            Administratrice · <?= e($moi['email']) ?><br>
            Compte créé le <?= e(format_date_fr($depuis)) ?>
        </p>
    </div>
</section>

<div class="profil-grille">
    <!-- Informations -->
    <form method="post" class="resa-bloc" id="infos">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="infos">
        <h2>Mes informations</h2>
        <div class="ligne-2">
            <div class="champ">
                <label for="p-prenom">Prénom</label>
                <input type="text" id="p-prenom" name="prenom" maxlength="80" value="<?= e($moi['prenom']) ?>" autocomplete="given-name" required>
            </div>
            <div class="champ">
                <label for="p-nom">Nom</label>
                <input type="text" id="p-nom" name="nom" maxlength="80" value="<?= e($moi['nom']) ?>" autocomplete="family-name" required>
            </div>
        </div>
        <div class="champ">
            <label for="p-tel">Téléphone <span class="discret">(facultatif)</span></label>
            <input type="tel" id="p-tel" name="telephone" value="<?= e($moi['telephone'] ?? '') ?>" placeholder="01 97 00 00 00" autocomplete="tel">
        </div>
        <button type="submit" class="btn btn-plein">Enregistrer</button>
    </form>

    <!-- Email de connexion -->
    <form method="post" class="resa-bloc" id="email">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="email">
        <h2>Email de connexion</h2>
        <p class="discret petit-note">C'est l'adresse que tu utilises pour te connecter. Ton mot de passe est demandé pour la changer.</p>
        <div class="champ">
            <label for="p-email">Adresse email</label>
            <input type="email" id="p-email" name="email" value="<?= e($moi['email'] ?? '') ?>" autocomplete="email" required>
        </div>
        <div class="champ">
            <label for="p-mdp-email">Mot de passe actuel</label>
            <input type="password" id="p-mdp-email" name="mot_de_passe_email" autocomplete="current-password" required>
        </div>
        <button type="submit" class="btn">Changer l'adresse</button>
    </form>

    <!-- Mot de passe -->
    <form method="post" class="resa-bloc" id="mot-de-passe">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="mot_de_passe">
        <h2>Mot de passe</h2>
        <div class="champ">
            <label for="p-actuel">Mot de passe actuel</label>
            <input type="password" id="p-actuel" name="actuel" autocomplete="current-password" required>
        </div>
        <div class="ligne-2">
            <div class="champ">
                <label for="p-nouveau">Nouveau</label>
                <input type="password" id="p-nouveau" name="nouveau" minlength="10" autocomplete="new-password" required>
            </div>
            <div class="champ">
                <label for="p-confirm">Confirmation</label>
                <input type="password" id="p-confirm" name="confirmation" minlength="10" autocomplete="new-password" required>
            </div>
        </div>
        <p class="discret petit-note">10 caractères minimum. Mélange lettres, chiffres et un symbole.</p>
        <button type="submit" class="btn">Changer le mot de passe</button>
    </form>

    <!-- Notifications -->
    <section class="resa-bloc">
        <h2>Notifications</h2>
        <p>Les emails de l'institut (nouvelles réservations, annulations, acomptes, messages de contact) arrivent à :</p>
        <p class="notif-adresse"><?= e($notifVers) ?></p>
        <!--p class="discret petit-note">
            <?php if (ADMIN_NOTIFICATION_EMAIL !== ''): ?>
                Adresse réglée dans <code>config/config.php</code> (ADMIN_NOTIFICATION_EMAIL).
            <?php else: ?>
                Aucune adresse n'est réglée dans <code>config/config.php</code> : les emails partent vers <?= $nbAdmins > 1 ? 'les ' . $nbAdmins . ' comptes administrateurs' : 'ton compte' ?>.
            <?php endif; ?>
        </p-->
    </section>
</div>

<?php require __DIR__ . '/../includes/admin_footer.php';
