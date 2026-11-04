<?php
/**
 * Messages reçus par le formulaire de contact : lire, répondre (email, WhatsApp, appel), marquer lu, supprimer.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/contact.php';
require_once __DIR__ . '/../includes/reservation.php';   // format_date_fr()
require_admin();

$urlPage = ADMIN_URL . '/messages';
const LIBELLES_SUJETS = [
    'question'    => 'Question sur un soin',
    'rendez-vous' => 'Rendez-vous',
    'cadeau'      => 'Carte cadeau / événement',
    'autre'       => 'Autre',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $id     = (int) ($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? '';

    if ($action === 'lu' && $id) {
        db()->prepare('UPDATE messages_contact SET lu = 1 - lu WHERE id = ?')->execute([$id]);
    } elseif ($action === 'supprimer' && $id) {
        db()->prepare('DELETE FROM messages_contact WHERE id = ?')->execute([$id]);
        flash('succes', 'Message supprimé.');
    } elseif ($action === 'tout_lu') {
        db()->exec('UPDATE messages_contact SET lu = 1 WHERE lu = 0');
        flash('succes', 'Tous les messages sont marqués comme lus.');
    }
    redirect($urlPage . (isset($_POST['filtre']) && $_POST['filtre'] === 'non-lus' ? '?filtre=non-lus' : '') . ($id && $action === 'lu' ? '#message-' . $id : ''));
}

$filtre   = ($_GET['filtre'] ?? '') === 'non-lus' ? 'non-lus' : 'tous';
$messages = db()->query(
    'SELECT * FROM messages_contact' . ($filtre === 'non-lus' ? ' WHERE lu = 0' : '') . ' ORDER BY created_at DESC LIMIT 200'
)->fetchAll();
$nbNonLus = (int) db()->query('SELECT COUNT(*) FROM messages_contact WHERE lu = 0')->fetchColumn();

$pageTitle   = 'Messages';
$activeAdmin = 'messages';
require __DIR__ . '/../includes/admin_header.php';
?>

<section class="entete-page entete-messages">
    <div>
        <h1>Messages</h1>
        <p class="discret"><?= $nbNonLus ?> non lu<?= $nbNonLus > 1 ? 's' : '' ?>. Les messages arrivent depuis la page Contact du site.</p>
    </div>
    <?php if ($nbNonLus): ?>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="tout_lu">
            <button type="submit" class="btn-action">Tout marquer comme lu</button>
        </form>
    <?php endif; ?>
</section>

<nav class="filtres" aria-label="Filtrer">
    <a href="<?= $urlPage ?>" class="pastille<?= $filtre === 'tous' ? ' pastille-active' : '' ?>">Tous</a>
    <a href="<?= $urlPage ?>?filtre=non-lus" class="pastille<?= $filtre === 'non-lus' ? ' pastille-active' : '' ?>">Non lus (<?= $nbNonLus ?>)</a>
</nav>

<?php if (!$messages): ?>
    <p class="discret"><?= $filtre === 'non-lus' ? 'Aucun message non lu.' : 'Aucun message pour l\'instant.' ?></p>
<?php else: ?>
    <div class="liste-messages">
        <?php foreach ($messages as $m):
            $date = new DateTimeImmutable($m['created_at']);
            $wa   = $m['telephone'] ? lien_whatsapp('Bonjour ' . $m['nom'] . ', ici ' . APP_NAME . ', suite à votre message : ', $m['telephone']) : '';
        ?>
            <article class="message-carte<?= $m['lu'] ? '' : ' message-non-lu' ?>" id="message-<?= (int) $m['id'] ?>">
                <header class="message-entete">
                    <div>
                        <strong><?= e($m['nom']) ?></strong>
                        <?php if (!$m['lu']): ?><span class="badge badge-attente">Nouveau</span><?php endif; ?>
                        <br>
                        <span class="discret petit-note"><?= e(LIBELLES_SUJETS[$m['sujet']] ?? $m['sujet']) ?> · <?= e(format_date_fr($date, true)) ?></span>
                    </div>
                </header>

                <p class="message-texte"><?= nl2br(e($m['message'])) ?></p>

                <div class="message-actions">
                    <?php if ($m['email']): ?>
                        <a class="btn-action" href="mailto:<?= e($m['email']) ?>?subject=<?= rawurlencode('Re : votre message à ' . APP_NAME) ?>"><?= e($m['email']) ?></a>
                    <?php endif; ?>
                    <?php if ($m['telephone']): ?>
                        <a class="btn-action" href="tel:<?= e($m['telephone']) ?>"><?= e($m['telephone']) ?></a>
                        <?php if ($wa): ?><a class="btn-action lien-whatsapp" href="<?= e($wa) ?>" target="_blank" rel="noopener">WhatsApp</a><?php endif; ?>
                    <?php endif; ?>
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="lu">
                        <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                        <input type="hidden" name="filtre" value="<?= $filtre ?>">
                        <button type="submit" class="btn-action"><?= $m['lu'] ? 'Marquer non lu' : 'Marquer lu' ?></button>
                    </form>
                    <form method="post" data-confirmer="Supprimer ce message ?">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="supprimer">
                        <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                        <button type="submit" class="btn-action btn-action-danger">Supprimer</button>
                    </form>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/admin_footer.php';
