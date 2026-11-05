<?php
/**
 * Boîte à suggestions : idées et problèmes signalés par les visiteurs, envoyés par email au développeur.
 * Adresse à renseigner dans config/config.php : define('DEVELOPPEUR_EMAIL', 'adresse@exemple.com');
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mailer.php';

const TYPES_SUGGESTION = [
    'idee'     => 'Une idée ou une amélioration',
    'probleme' => 'Un problème rencontré sur le site',
    'autre'    => 'Autre',
];
const SUGGESTIONS_MAX_PAR_HEURE = 3;

$emailDev = defined('DEVELOPPEUR_EMAIL') ? trim((string) DEVELOPPEUR_EMAIL) : '';

/** Page d'où vient la personne : uniquement un chemin du site (jamais une adresse extérieure). */
function page_d_origine(string $valeur): string
{
    $valeur = trim($valeur);
    return preg_match('#^/[^\s<>"]{0,200}$#', $valeur) ? $valeur : '';
}

// ---------- Envoi ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $type    = (string) ($_POST['type'] ?? '');
    $message = trim($_POST['message'] ?? '');
    $email   = mb_strtolower(trim($_POST['email'] ?? ''));
    $depuis  = page_d_origine((string) ($_POST['depuis'] ?? ''));

    // Pièges à robots : champ caché rempli, ou envoi en moins de 3 secondes
    $robot = trim($_POST['site_web'] ?? '') !== ''
          || (time() - (int) ($_SESSION['suggestion_affiche'] ?? 0)) < 3;

    // Limite : quelques envois par heure et par visiteur
    $_SESSION['suggestions_envoyees'] = array_filter(
        $_SESSION['suggestions_envoyees'] ?? [],
        fn ($t) => $t > time() - 3600
    );

    $erreurs = [];
    if ($emailDev === '')                       { $erreurs[] = 'La boîte à suggestions n\'est pas encore configurée. Merci de réessayer plus tard.'; }
    if (!isset(TYPES_SUGGESTION[$type]))        { $erreurs[] = 'Choisis le type de suggestion.'; }
    if (mb_strlen($message) < 10)               { $erreurs[] = 'Ta suggestion est un peu courte (10 caractères minimum).'; }
    if (mb_strlen($message) > 2000)             { $erreurs[] = 'Ta suggestion est trop longue (2 000 caractères maximum).'; }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { $erreurs[] = 'L\'email n\'est pas valide (tu peux le laisser vide).'; }
    if (count($_SESSION['suggestions_envoyees']) >= SUGGESTIONS_MAX_PAR_HEURE
        || limite_atteinte('suggestion_ip', ip_client(), 10, 3600)) {
        $erreurs[] = 'Merci pour toutes ces idées ! Tu pourras en envoyer d\'autres dans un moment.';
    }

    if (!$erreurs) {
        if (!$robot) {   // un robot croit que c'est parti, mais rien n'est envoyé
            $user = current_user();
            $lignes = ['Type' => e(TYPES_SUGGESTION[$type])];
            if ($user)         { $lignes['Envoyée par'] = e($user['prenom'] . ' ' . $user['nom']) . ' (cliente connectée)'; }
            if ($email !== '') { $lignes['Email'] = '<a href="mailto:' . e($email) . '">' . e($email) . '</a>'; }
            if ($depuis !== '') { $lignes['Page'] = e($depuis); }
            $lignes['Navigateur'] = e(mb_substr($_SERVER['HTTP_USER_AGENT'] ?? 'inconnu', 0, 160));

            $ok = envoyer_email(
                [['email' => $emailDev]],
                '[' . APP_NAME . '] Suggestion : ' . TYPES_SUGGESTION[$type],
                gabarit_email('Nouvelle suggestion sur le site',
                    tableau_email($lignes)
                    . '<p style="white-space:pre-line;background:#faf6f8;border-left:3px solid #8c3a5b;padding:12px 16px;border-radius:6px;">'
                    . e($message) . '</p>',
                    TYPES_SUGGESTION[$type]),
                $email !== '' ? ['email' => $email] : null
            );

            // Si l'email échoue, la suggestion n'est pas perdue : elle est gardée dans logs/suggestions.log
            if (!$ok) {
                @file_put_contents(
                    __DIR__ . '/../logs/suggestions.log',
                    date('Y-m-d H:i:s') . ' | ' . $type . ' | ' . ($email ?: '-') . ' | ' . ($depuis ?: '-') . ' | '
                        . str_replace(["\r", "\n"], ' ', $message) . PHP_EOL,
                    FILE_APPEND | LOCK_EX
                );
            }
            $_SESSION['suggestions_envoyees'][] = time();
            limite_noter('suggestion_ip', ip_client());
        }
        clear_old();
        unset($_SESSION['suggestion_affiche']);
        $_SESSION['suggestion_envoyee'] = true;
        redirect(BASE_URL . '/suggestion');
    }

    set_old($_POST);
    $_SESSION['erreurs_suggestion'] = $erreurs;
    redirect(BASE_URL . '/suggestion' . ($depuis !== '' ? '?depuis=' . rawurlencode($depuis) : ''));
}

// ---------- Affichage ----------
$_SESSION['suggestion_affiche'] = time();
$erreurs = $_SESSION['erreurs_suggestion'] ?? [];
$envoyee = !empty($_SESSION['suggestion_envoyee']);
unset($_SESSION['erreurs_suggestion'], $_SESSION['suggestion_envoyee']);
$depuis = page_d_origine((string) ($_SESSION['old']['depuis'] ?? $_GET['depuis'] ?? ''));
$moi = current_user();

$pageTitle  = 'Faire une suggestion';
$activePage = '';
require __DIR__ . '/../includes/header.php';
?>

<section class="carte-formulaire carte-suggestion">
    <h1>Faire une suggestion</h1>
    <p class="discret">Une idée pour améliorer le site, ou quelque chose qui ne marche pas comme prévu ? Dis-le-nous, chaque message est lu.</p>

    <?php if ($envoyee): ?>
        <div class="alerte alerte-succes" role="status">
            Merci pour ta suggestion, elle est bien partie !
        </div>
        <p><a href="<?= BASE_URL ?>/">Retour à l'accueil</a> · <a href="<?= BASE_URL ?>/suggestion">Envoyer une autre suggestion</a></p>
    <?php else: ?>
        <?php if ($erreurs): ?>
            <div class="alerte alerte-erreur" role="alert">
                <ul><?php foreach ($erreurs as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
            </div>
        <?php endif; ?>

        <form method="post" action="<?= BASE_URL ?>/suggestion" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="depuis" value="<?= e($depuis) ?>">

            <fieldset class="choix-type">
                <legend>C'est plutôt…</legend>
                <?php $typeChoisi = $_SESSION['old']['type'] ?? 'idee'; ?>
                <?php foreach (TYPES_SUGGESTION as $cle => $libelle): ?>
                    <label class="type-option">
                        <input type="radio" name="type" value="<?= $cle ?>" <?= $typeChoisi === $cle ? 'checked' : '' ?>>
                        <span><?= e($libelle) ?></span>
                    </label>
                <?php endforeach; ?>
            </fieldset>

            <div class="champ">
                <label for="s-message">Ta suggestion</label>
                <textarea id="s-message" name="message" rows="6" minlength="10" maxlength="2000" required
                          placeholder="Ex. Ce serait pratique de pouvoir réserver pour deux personnes en même temps."><?= old('message') ?></textarea>
            </div>

            <div class="champ">
                <label for="s-email">Ton email <span class="discret">(facultatif, si tu veux une réponse)</span></label>
                <input type="email" id="s-email" name="email" maxlength="190" autocomplete="email"
                       value="<?= isset($_SESSION['old']['email']) ? old('email') : e($moi['email'] ?? '') ?>">
            </div>

            <div class="champ-piege" aria-hidden="true">
                <label for="s-site">Ne pas remplir</label>
                <input type="text" id="s-site" name="site_web" tabindex="-1" autocomplete="off">
            </div>

            <button type="submit" class="btn btn-plein">Envoyer la suggestion</button>
        </form>
    <?php endif; ?>
</section>
<?php clear_old(); ?>

<?php require __DIR__ . '/../includes/footer.php';
