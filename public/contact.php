<?php
/**
 * Page Contact : WhatsApp, téléphone, adresse, horaires, carte et formulaire de message.
 * Les messages sont enregistrés (Admin > Messages) et envoyés en copie à l'institut.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/contact.php';
require_once __DIR__ . '/../includes/mailer.php';

const SUJETS_CONTACT = [
    'question'    => 'Question sur un soin ou un tarif',
    'rendez-vous' => 'Mon rendez-vous',
    'cadeau'      => 'Carte cadeau ou événement',
    'autre'       => 'Autre',
];
const CONTACT_MAX_PAR_HEURE = 5;

// ---------- Envoi du formulaire ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $nom       = mb_substr(trim($_POST['nom'] ?? ''), 0, 80);
    $email     = mb_strtolower(trim($_POST['email'] ?? ''));
    $telephone = mb_substr(preg_replace('/[^\d+ ]/', '', $_POST['telephone'] ?? ''), 0, 30);
    $sujet     = (string) ($_POST['sujet'] ?? '');
    $message   = trim($_POST['message'] ?? '');
    $ip        = $_SERVER['REMOTE_ADDR'] ?? null;

    // Pièges à robots : champ caché rempli, ou formulaire envoyé en moins de 3 secondes
    $robot = trim($_POST['site_web'] ?? '') !== ''
          || (time() - (int) ($_SESSION['contact_affiche'] ?? 0)) < 3;

    $erreurs = [];
    if ($nom === '')                                         { $erreurs[] = 'Indique ton nom.'; }
    if ($email === '' && trim($telephone) === '')            { $erreurs[] = 'Laisse un email ou un téléphone pour qu\'on puisse te répondre.'; }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { $erreurs[] = 'L\'email n\'est pas valide.'; }
    if (!isset(SUJETS_CONTACT[$sujet]))                      { $erreurs[] = 'Choisis un sujet.'; }
    if (mb_strlen($message) < 10)                            { $erreurs[] = 'Ton message est un peu court (10 caractères minimum).'; }
    if (mb_strlen($message) > 2000)                          { $erreurs[] = 'Ton message est trop long (2 000 caractères maximum).'; }

    if (!$erreurs && !$robot) {
        $stmt = db()->prepare('SELECT COUNT(*) FROM messages_contact WHERE ip = ? AND created_at > NOW() - INTERVAL 1 HOUR');
        $stmt->execute([$ip]);
        if ((int) $stmt->fetchColumn() >= CONTACT_MAX_PAR_HEURE) {
            $erreurs[] = 'Tu as déjà envoyé plusieurs messages. Réessaie dans un moment, ou écris-nous sur WhatsApp.';
        }
    }

    if (!$erreurs) {
        if (!$robot) {   // un robot croit que c'est parti, mais rien n'est enregistré
            $user = current_user();
            db()->prepare(
                'INSERT INTO messages_contact (utilisateur_id, nom, email, telephone, sujet, message, ip) VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([$user['id'] ?? null, $nom, $email !== '' ? $email : null, trim($telephone) !== '' ? trim($telephone) : null, $sujet, $message, $ip]);

            // Email à l'admin : adresse de notification (config.php), sinon tous les comptes administrateurs
            $destinataires = [];
            if (ADMIN_NOTIFICATION_EMAIL !== '') {
                $destinataires[] = ['email' => ADMIN_NOTIFICATION_EMAIL];
            } else {
                $admins = db()->query("SELECT email, prenom FROM utilisateurs WHERE role = 'admin' AND email IS NOT NULL")->fetchAll();
                foreach ($admins as $a) {
                    $destinataires[] = ['email' => $a['email'], 'name' => $a['prenom']];
                }
            }
            if ($destinataires) {
                $lignes = ['De' => e($nom), 'Sujet' => e(SUJETS_CONTACT[$sujet])];
                if ($email !== '')           { $lignes['Email'] = '<a href="mailto:' . e($email) . '">' . e($email) . '</a>'; }
                if (trim($telephone) !== '') { $lignes['Téléphone'] = e($telephone); }
                envoyer_email(
                    $destinataires,
                    'Nouveau message de ' . $nom . ' : ' . SUJETS_CONTACT[$sujet],
                    gabarit_email('Nouveau message du site',
                        '<p>Un message vient d\'arriver depuis la page Contact. Il est aussi enregistré dans <strong>Admin → Messages</strong>.</p>'
                        . tableau_email($lignes)
                        . '<p style="white-space:pre-line;background:#faf6f8;border-left:3px solid #8c3a5b;padding:12px 16px;border-radius:6px;">' . e($message) . '</p>'
                        . bouton_email('Ouvrir les messages', ADMIN_URL . '/messages?filtre=non-lus'),
                        'Message de ' . $nom),
                    $email !== '' ? ['email' => $email, 'name' => $nom] : null   // « Répondre » écrit à la cliente
                );
            } else {
                error_log('[Contact] Aucun destinataire : renseigne ADMIN_NOTIFICATION_EMAIL dans config.php.');
            }
        }
        clear_old();
        unset($_SESSION['contact_affiche']);
        $_SESSION['contact_envoye'] = $nom;   // message de succès affiché au niveau du formulaire
        redirect(BASE_URL . '/contact#formulaire');
    }

    set_old($_POST);
    $_SESSION['erreurs_contact'] = $erreurs;
    redirect(BASE_URL . '/contact#formulaire');
}

$_SESSION['contact_affiche'] = time();
$erreursContact = $_SESSION['erreurs_contact'] ?? [];
$contactEnvoye  = $_SESSION['contact_envoye'] ?? null;
unset($_SESSION['erreurs_contact'], $_SESSION['contact_envoye']);

// Pré-remplissage pour une cliente connectée
$moi = current_user();
$valeur = function (string $champ, ?string $defaut = null): string {
    return isset($_SESSION['old'][$champ]) ? e($_SESSION['old'][$champ]) : e($defaut ?? '');
};

$whatsapp = lien_whatsapp();
$carte    = lien_carte();
$integree = carte_integree();

$pageTitle  = 'Contact';
$activePage = 'contact';
require __DIR__ . '/../includes/header.php';
?>

<section class="entete-page">
    <h1>Nous contacter</h1>
    <p class="discret">Une question sur un soin, un tarif ou un rendez-vous ? Écris-nous sur WhatsApp ou laisse-nous un message ci-dessous.</p>
</section>

<div class="contact-grille">
    <div class="contact-infos">
        <?php if ($whatsapp): ?>
            <a href="<?= e($whatsapp) ?>" class="btn btn-plein btn-whatsapp" target="_blank" rel="noopener">
                <?= icone_discussion() ?> Écrire sur WhatsApp
            </a>
        <?php endif; ?>

        <dl class="contact-liste">
            <?php if (SALON_TELEPHONE !== ''): ?>
                <div>
                    <dt>Téléphone</dt>
                    <?php $tel = numero_international(SALON_TELEPHONE); ?>
                    <dd><a href="tel:<?= e($tel ? '+' . $tel : SALON_TELEPHONE) ?>"><?= e(SALON_TELEPHONE) ?></a></dd>
                </div>
            <?php endif; ?>
            <?php if (SALON_ADRESSE !== ''): ?>
                <div>
                    <dt>Adresse</dt>
                    <dd>
                        <?= e(SALON_ADRESSE) ?>
                        <?php if ($carte): ?>
                            <br><a href="<?= e($carte) ?>" target="_blank" rel="noopener">Ouvrir dans Google Maps</a>
                        <?php endif; ?>
                    </dd>
                </div>
            <?php endif; ?>
            <div>
                <dt>Horaires</dt>
                <dd>
                    <?php foreach (resume_horaires() as $ligneHoraire): ?>
                        <?= e($ligneHoraire) ?><br>
                    <?php endforeach; ?>
                </dd>
            </div>
        </dl>

        <a href="<?= BASE_URL ?>/reserver" class="btn">Réserver en ligne</a>
    </div>

    <?php if ($integree): ?>
        <div class="contact-carte">
            <iframe src="<?= e($integree) ?>" title="Plan d'accès à <?= e(APP_NAME) ?>" loading="lazy"></iframe>
        </div>
    <?php endif; ?>
</div>

<section class="contact-formulaire" id="formulaire">
    <h2>Envoyer un message</h2>
    <p class="discret">On te répond par email ou par téléphone, en général dans la journée.</p>

    <?php if ($contactEnvoye !== null): ?>
        <div class="alerte alerte-succes" role="status">
            Merci <?= e($contactEnvoye) ?>, ton message est bien envoyé. On te répond au plus vite.
        </div>
    <?php endif; ?>
    <?php if ($erreursContact): ?>
        <div class="alerte alerte-erreur" role="alert">
            <ul><?php foreach ($erreursContact as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>

    <form method="post" action="<?= BASE_URL ?>/contact#formulaire" novalidate>
        <?= csrf_field() ?>
        <div class="ligne-2">
            <div class="champ">
                <label for="c-nom">Nom et prénom</label>
                <input type="text" id="c-nom" name="nom" maxlength="80" autocomplete="name" required
                       value="<?= $valeur('nom', $moi ? trim($moi['prenom'] . ' ' . $moi['nom']) : null) ?>">
            </div>
            <div class="champ">
                <label for="c-sujet">Sujet</label>
                <select id="c-sujet" name="sujet" required>
                    <?php $sujetChoisi = $_SESSION['old']['sujet'] ?? 'question'; ?>
                    <?php foreach (SUJETS_CONTACT as $cle => $libelle): ?>
                        <option value="<?= $cle ?>" <?= $sujetChoisi === $cle ? 'selected' : '' ?>><?= e($libelle) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="ligne-2">
            <div class="champ">
                <label for="c-email">Email</label>
                <input type="email" id="c-email" name="email" maxlength="190" autocomplete="email"
                       value="<?= $valeur('email', $moi['email'] ?? null) ?>">
            </div>
            <div class="champ">
                <label for="c-tel">Téléphone</label>
                <input type="tel" id="c-tel" name="telephone" maxlength="30" autocomplete="tel" placeholder="01 97 00 00 00"
                       value="<?= $valeur('telephone', $moi['telephone'] ?? null) ?>">
            </div>
        </div>
        <p class="discret petit-note">Email ou téléphone : au moins l'un des deux, pour qu'on puisse te répondre.</p>
        <div class="champ">
            <label for="c-message">Message</label>
            <textarea id="c-message" name="message" rows="5" minlength="10" maxlength="2000" required
                      placeholder="Ex. Je voudrais savoir si la pose semi-permanent tient sur ongles courts…"><?= $valeur('message') ?></textarea>
        </div>
        <!-- Piège à robots : invisible pour les humains -->
        <div class="champ-piege" aria-hidden="true">
            <label for="c-site">Ne pas remplir</label>
            <input type="text" id="c-site" name="site_web" tabindex="-1" autocomplete="off">
        </div>
        <p class="discret petit-note">En envoyant ce message, tu acceptes qu'on utilise ces informations pour te répondre (<a href="<?= BASE_URL ?>/confidentialite">confidentialité</a>).</p>
        <button type="submit" class="btn btn-plein">Envoyer le message</button>
    </form>
</section>
<?php clear_old(); ?>

<?php require __DIR__ . '/../includes/footer.php';
