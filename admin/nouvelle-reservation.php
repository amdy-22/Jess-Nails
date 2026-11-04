<?php
/**
 * Rendez-vous saisi par l'institut : par téléphone, WhatsApp ou cliente sans rendez-vous.
 * 1. Choisir (ou créer) la cliente  2. Soin, date, praticienne  3. Horaire et confirmation
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/reservation.php';
require_once __DIR__ . '/../includes/notifications.php';
require_admin();

$urlPage = ADMIN_URL . '/nouvelle-reservation';
const SOURCES_ADMIN = ['telephone' => 'Téléphone', 'whatsapp' => 'WhatsApp', 'sur_place' => 'Sur place (sans rendez-vous)'];
const DELAI_ADMIN = -14;   // l'admin peut réserver le quart d'heure en cours

/**
 * Téléphone saisi par l'admin : format béninois 01XXXXXXXX, ou numéro étranger (+228..., +234...).
 */
function telephone_admin(string $tel): ?string
{
    $tel = preg_replace('/[\s.\-()]/', '', $tel);
    $local = preg_replace('/^(\+229|00229)/', '', $tel);
    if (preg_match('/^01\d{8}$/', $local)) {
        return $local;
    }
    $tel = preg_replace('/^00/', '+', $tel);
    return preg_match('/^\+?\d{8,15}$/', $tel) ? $tel : null;
}

function cliente_par_id(int $id): ?array
{
    $stmt = db()->prepare('SELECT id, prenom, nom, email, telephone, mot_de_passe IS NOT NULL AS a_un_compte FROM utilisateurs WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

// =====================================================
// Traitements
// =====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    // ---------- Créer une cliente ----------
    if ($action === 'creer_cliente') {
        $prenom = trim($_POST['prenom'] ?? '');
        $nom    = trim($_POST['nom'] ?? '');
        $tel    = telephone_admin($_POST['telephone'] ?? '');
        $email  = mb_strtolower(trim($_POST['email'] ?? ''));

        $erreurs = [];
        if ($prenom === '' || $nom === '') { $erreurs[] = 'Indique le prénom et le nom.'; }
        if ($tel === null)                 { $erreurs[] = 'Numéro invalide : 01 XX XX XX XX, ou un numéro étranger avec son indicatif (+228…).'; }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { $erreurs[] = 'L\'email n\'est pas valide (laisse vide si elle n\'en a pas).'; }

        if (!$erreurs) {
            // Déjà connue (même téléphone ou même email) ? On la reprend au lieu de créer un doublon.
            $stmt = db()->prepare('SELECT id FROM utilisateurs WHERE telephone = ? OR (email IS NOT NULL AND email = ?) LIMIT 1');
            $stmt->execute([$tel, $email]);
            if ($existante = $stmt->fetchColumn()) {
                flash('info', 'Cette cliente existe déjà (même téléphone ou même email) : elle a été sélectionnée.');
                redirect($urlPage . '?client=' . (int) $existante);
            }
            db()->prepare(
                "INSERT INTO utilisateurs (nom, prenom, email, telephone, mot_de_passe, role) VALUES (?, ?, ?, ?, NULL, 'client')"
            )->execute([$nom, $prenom, $email !== '' ? $email : null, $tel]);
            flash('succes', 'Cliente ' . $prenom . ' ' . $nom . ' créée.');
            redirect($urlPage . '?client=' . (int) db()->lastInsertId());
        }

        set_old($_POST);
        $_SESSION['erreurs'] = $erreurs;
        redirect($urlPage . '?nouvelle=1#cliente');
    }

    // ---------- Enregistrer le rendez-vous ----------
    if ($action === 'reserver') {
        $client      = cliente_par_id((int) ($_POST['client'] ?? 0));
        $prestation  = combiner_soins(ids_soins_demandes($_POST));
        $jour        = jour_reservable((string) ($_POST['date'] ?? ''));
        $pratId      = (int) ($_POST['praticienne'] ?? 0);
        $heure       = (string) ($_POST['heure'] ?? '');
        $source      = (string) ($_POST['source'] ?? '');
        $note        = mb_substr(trim($_POST['note'] ?? ''), 0, 255);
        $envoyerMail = isset($_POST['envoyer_email']);
        $retour      = $urlPage . '?' . http_build_query(['client' => $client['id'] ?? 0, 'prestation' => $prestation['id'] ?? 0,
                                                         'autres' => $prestation['autres'] ?? [],
                                                         'date' => $_POST['date'] ?? '', 'praticienne' => $pratId]);

        if (!$client || !$prestation || !$jour || !preg_match('/^\d{2}:\d{2}$/', $heure) || !isset(SOURCES_ADMIN[$source])) {
            flash('erreur', 'Il manque une information : cliente, soin, date, horaire ou origine du rendez-vous.');
            redirect($retour);
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $libres = creneaux_disponibles($prestation, $jour, true, $pratId ?: null, DELAI_ADMIN);
            if (!isset($libres[$heure])) {
                $pdo->rollBack();
                flash('erreur', 'Ce créneau vient d\'être pris. Choisis-en un autre.');
                redirect($retour);
            }
            $debut = new DateTimeImmutable($jour->format('Y-m-d') . ' ' . $heure);
            $fin   = $debut->modify('+' . (int) $prestation['duree_minutes'] . ' minutes');
            $pdo->prepare(
                "INSERT INTO reservations
                    (client_id, prestation_id, libelle, praticienne_id, debut, fin, prix_fcfa, acompte_fcfa, statut, source, note_client)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'confirmee', ?, ?)"
            )->execute([
                $client['id'], $prestation['id'], $prestation['nom'], $libres[$heure],
                $debut->format('Y-m-d H:i:s'), $fin->format('Y-m-d H:i:s'),
                $prestation['prix_fcfa'], $prestation['acompte_fcfa'], $source, $note !== '' ? $note : null,
            ]);
            $id = (int) $pdo->lastInsertId();
            enregistrer_soins_reservation($id, $prestation);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $message = 'Rendez-vous enregistré : ' . $client['prenom'] . ' ' . $client['nom'] . ', ' . $prestation['nom']
                 . ', ' . format_date_fr($debut, true) . '.';
        if ($envoyerMail && $client['email']) {
            try {
                notifier_reservation_confirmee($id, false);
                $message .= ' Email de confirmation envoyé.';
            } catch (Throwable $e) {
                error_log('[Emails] ' . $e->getMessage());
            }
        }
        flash('succes', $message);
        redirect(ADMIN_URL . '/reservations?du=' . $jour->format('Y-m-d') . '&au=' . $jour->format('Y-m-d'));
    }

    redirect($urlPage);
}

// =====================================================
// État de la page (GET)
// =====================================================
$client     = !empty($_GET['client']) ? cliente_par_id((int) $_GET['client']) : null;
$recherche  = trim($_GET['q'] ?? '');
$nouvelle   = !empty($_GET['nouvelle']);
$erreurs    = $_SESSION['erreurs'] ?? [];
unset($_SESSION['erreurs']);

$resultats = [];
if (!$client && $recherche !== '') {
    $like = '%' . $recherche . '%';
    $chiffres = preg_replace('/\D/', '', $recherche);
    $sql = "SELECT id, prenom, nom, email, telephone FROM utilisateurs
            WHERE role = 'client' AND (prenom LIKE ? OR nom LIKE ? OR email LIKE ? OR CONCAT(prenom, ' ', nom) LIKE ?"
         . (strlen($chiffres) >= 3 ? ' OR telephone LIKE ?' : '') . ')
            ORDER BY prenom, nom LIMIT 20';
    $params = [$like, $like, $like, $like];
    if (strlen($chiffres) >= 3) {
        $params[] = '%' . $chiffres . '%';
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $resultats = $stmt->fetchAll();
}

$soins = db()->query(
    'SELECT p.id, p.nom, p.duree_minutes, p.prix_fcfa, c.nom AS categorie
     FROM prestations p JOIN categories c ON c.id = p.categorie_id
     WHERE p.actif = 1 ORDER BY c.ordre, p.prix_fcfa'
)->fetchAll();
$praticiennes = db()->query('SELECT id, nom FROM praticiennes WHERE actif = 1 ORDER BY nom')->fetchAll();

$prestation = combiner_soins(ids_soins_demandes($_GET));
$aujourdhui = new DateTimeImmutable('today');
$jour       = jour_reservable((string) ($_GET['date'] ?? $aujourdhui->format('Y-m-d')));
$pratId     = (int) ($_GET['praticienne'] ?? 0);
$creneaux   = ($client && $prestation && $jour) ? creneaux_disponibles($prestation, $jour, false, $pratId ?: null, DELAI_ADMIN) : null;
$fermeture  = $jour ? fermeture_du_jour($jour) : null;

$pageTitle   = 'Nouveau rendez-vous';
$activeAdmin = 'reservations';
require __DIR__ . '/../includes/admin_header.php';
?>

<section class="entete-page">
    <h1>Nouveau rendez-vous</h1>
    <p class="discret">Pour une cliente qui appelle, écrit sur WhatsApp ou se présente sans rendez-vous.</p>
</section>

<div class="etapes-admin">

    <!-- 1. Cliente -->
    <section class="resa-bloc" id="cliente">
        <h2><span class="num">1</span> Cliente</h2>

        <?php if ($client): ?>
            <div class="cliente-choisie">
                <div>
                    <strong><?= e($client['prenom'] . ' ' . $client['nom']) ?></strong><br>
                    <span class="discret"><?= e($client['telephone']) ?><?= $client['email'] ? ' · ' . e($client['email']) : ' · pas d\'email' ?></span>
                </div>
                <a class="btn-action" href="<?= $urlPage ?>">Changer</a>
            </div>
        <?php else: ?>
            <form method="get" class="form-recherche">
                <div class="champ">
                    <label for="q">Rechercher une cliente</label>
                    <input type="search" id="q" name="q" value="<?= e($recherche) ?>" placeholder="Nom, prénom, téléphone ou email" autofocus>
                </div>
                <button type="submit" class="btn">Rechercher</button>
            </form>

            <?php if ($recherche !== ''): ?>
                <?php if (!$resultats): ?>
                    <p class="discret">Aucune cliente trouvée pour « <?= e($recherche) ?> ».</p>
                <?php else: ?>
                    <div class="liste-simple">
                        <?php foreach ($resultats as $c): ?>
                            <div class="ligne-simple">
                                <div>
                                    <strong><?= e($c['prenom'] . ' ' . $c['nom']) ?></strong><br>
                                    <span class="discret petit-note"><?= e($c['telephone']) ?><?= $c['email'] ? ' · ' . e($c['email']) : '' ?></span>
                                </div>
                                <a class="btn-action" href="?client=<?= (int) $c['id'] ?>">Choisir</a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <details class="nouvelle-cliente" <?= $nouvelle || ($recherche !== '' && !$resultats) ? 'open' : '' ?>>
                <summary>Nouvelle cliente</summary>
                <?php if ($erreurs): ?>
                    <div class="alerte alerte-erreur" role="alert"><ul><?php foreach ($erreurs as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
                <?php endif; ?>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="creer_cliente">
                    <div class="ligne-2">
                        <div class="champ"><label for="prenom">Prénom</label><input type="text" id="prenom" name="prenom" value="<?= old('prenom') ?>" required></div>
                        <div class="champ"><label for="nom">Nom</label><input type="text" id="nom" name="nom" value="<?= old('nom') ?>" required></div>
                    </div>
                    <div class="ligne-2">
                        <div class="champ"><label for="telephone">Téléphone</label><input type="tel" id="telephone" name="telephone" value="<?= old('telephone') ?>" placeholder="01 97 12 34 56" required></div>
                        <div class="champ"><label for="email">Email (facultatif)</label><input type="email" id="email" name="email" value="<?= old('email') ?>"></div>
                    </div>
                    <button type="submit" class="btn btn-plein">Créer la cliente</button>
                </form>
                <?php clear_old(); ?>
            </details>
        <?php endif; ?>
    </section>

    <?php if ($client): ?>
    <!-- 2. Soin, date, praticienne -->
    <form method="get" class="resa-bloc" id="form-choix">
        <h2><span class="num">2</span> Soins et date</h2>
        <input type="hidden" name="client" value="<?= (int) $client['id'] ?>">
        <div class="ligne-3">
            <div class="champ">
                <label for="prestation">Soin</label>
                <select id="prestation" name="prestation" required>
                    <option value="">Choisir…</option>
                    <?php $cat = null; foreach ($soins as $s):
                        if ($s['categorie'] !== $cat): if ($cat !== null) echo '</optgroup>'; $cat = $s['categorie']; ?>
                            <optgroup label="<?= e($cat) ?>">
                        <?php endif; ?>
                        <option value="<?= (int) $s['id'] ?>" <?= $prestation && (int) $prestation['id'] === (int) $s['id'] ? 'selected' : '' ?>>
                            <?= e($s['nom']) ?> · <?= format_duree((int) $s['duree_minutes']) ?> · <?= format_fcfa((int) $s['prix_fcfa']) ?>
                        </option>
                    <?php endforeach; if ($cat !== null) echo '</optgroup>'; ?>
                </select>
            </div>
            <div class="champ">
                <label for="date">Date</label>
                <input type="date" id="date" name="date" value="<?= e($jour ? $jour->format('Y-m-d') : '') ?>"
                       min="<?= $aujourdhui->format('Y-m-d') ?>" max="<?= $aujourdhui->modify('+' . HORIZON_JOURS . ' days')->format('Y-m-d') ?>" required>
            </div>
            <div class="champ">
                <label for="praticienne">Praticienne</label>
                <select id="praticienne" name="praticienne">
                    <option value="0">La moins chargée</option>
                    <?php foreach ($praticiennes as $p): ?>
                        <option value="<?= (int) $p['id'] ?>" <?= $pratId === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['nom']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <?php require __DIR__ . '/../includes/choix_soins.php'; ?>
        <button type="submit" class="btn">Voir les horaires libres</button>
    </form>

    <!-- 3. Horaire et confirmation -->
    <section class="resa-bloc">
        <h2><span class="num">3</span> Horaire</h2>

        <?php if ($creneaux === null): ?>
            <p class="discret">Choisis d'abord le soin et la date.</p>
        <?php elseif ($fermeture !== null): ?>
            <p class="alerte alerte-info">L'institut est fermé ce jour-là (<?= e($fermeture) ?>).</p>
        <?php elseif (!$creneaux): ?>
            <p class="alerte alerte-info">Plus aucun horaire libre ce jour-là pour ce soin<?= $pratId ? ' avec cette praticienne' : '' ?>.</p>
        <?php else: ?>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="reserver">
                <input type="hidden" name="client" value="<?= (int) $client['id'] ?>">
                <input type="hidden" name="prestation" value="<?= (int) $prestation['id'] ?>">
                <?php foreach ($prestation['autres'] as $idAutre): ?>
                    <input type="hidden" name="autres[]" value="<?= $idAutre ?>">
                <?php endforeach; ?>
                <input type="hidden" name="date" value="<?= e($jour->format('Y-m-d')) ?>">
                <input type="hidden" name="praticienne" value="<?= $pratId ?>">

                <p class="discret"><?= e(ucfirst(format_date_fr($jour))) ?> · <?= e($prestation['nom']) ?> (<?= format_duree((int) $prestation['duree_minutes']) ?>) · <?= format_fcfa((int) $prestation['prix_fcfa']) ?></p>

                <?php foreach (grouper_creneaux($creneaux) as $moment => $heures): ?>
                    <fieldset class="groupe-creneaux">
                        <legend><?= e($moment) ?></legend>
                        <div class="creneaux">
                            <?php foreach ($heures as $heure): $idc = 'h' . str_replace(':', '', $heure); ?>
                                <input type="radio" name="heure" id="<?= $idc ?>" value="<?= e($heure) ?>" required>
                                <label for="<?= $idc ?>"><?= e(str_replace(':', 'h', $heure)) ?></label>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>
                <?php endforeach; ?>

                <div class="ligne-2">
                    <div class="champ">
                        <label for="source">Rendez-vous pris par</label>
                        <select id="source" name="source" required>
                            <?php foreach (SOURCES_ADMIN as $val => $lib): ?>
                                <option value="<?= $val ?>"><?= e($lib) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="champ">
                        <label for="note">Précision (facultatif)</label>
                        <input type="text" id="note" name="note" maxlength="255" placeholder="Ex. couleur de vernis">
                    </div>
                </div>

                <?php if ($client['email']): ?>
                    <label class="case"><input type="checkbox" name="envoyer_email" value="1" checked> Envoyer l'email de confirmation à <?= e($client['email']) ?></label>
                <?php else: ?>
                    <p class="discret petit-note">Cette cliente n'a pas d'email : pense à lui confirmer le rendez-vous par téléphone ou WhatsApp.</p>
                <?php endif; ?>

                <button type="submit" class="btn btn-plein btn-large">Enregistrer le rendez-vous</button>
                <p class="discret petit-note">Le rendez-vous est confirmé tout de suite, règlement sur place.</p>
            </form>
        <?php endif; ?>
    </section>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/admin_footer.php';
