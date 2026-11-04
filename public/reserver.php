<?php
/**
 * Réservation d'une séance (clients connectés uniquement).
 * 1. choix du ou des soins et de la date (GET)  2. choix du créneau (POST)
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/reservation.php';
require_login();   // pas connecté -> connexion/inscription, puis retour ici

$user = current_user();

// Liste des soins pour le menu déroulant
$soins = db()->query(
    'SELECT p.id, p.nom, p.duree_minutes, p.prix_fcfa, c.nom AS categorie
     FROM prestations p JOIN categories c ON c.id = p.categorie_id
     WHERE p.actif = 1 ORDER BY c.ordre, p.prix_fcfa'
)->fetchAll();
$soinsParCategorie = [];
foreach ($soins as $s) {
    $soinsParCategorie[$s['categorie']][] = $s;
}

// Choix en cours (GET pour l'affichage, POST pour l'enregistrement)
$source       = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$idsSoins     = ids_soins_demandes($source);
$prestationId = $idsSoins[0] ?? 0;
$dateChoisie  = trim($source['date'] ?? '');
$prestation   = combiner_soins($idsSoins);   // un ou plusieurs soins, enchaînés
$jour         = $dateChoisie !== '' ? jour_reservable($dateChoisie) : null;

// Praticienne choisie (0 = sans préférence)
$praticiennesActives = db()->query('SELECT id, nom FROM praticiennes WHERE actif = 1 ORDER BY nom')->fetchAll();
$praticienneId = (int) ($source['praticienne'] ?? 0);
if ($praticienneId && !in_array($praticienneId, array_map('intval', array_column($praticiennesActives, 'id')), true)) {
    $praticienneId = 0;   // praticienne inconnue ou désactivée : sans préférence
}
$nomPraticienne = $praticienneId ? array_column($praticiennesActives, 'nom', 'id')[$praticienneId] : null;

$aujourdhui = new DateTimeImmutable('today');
$dateMax    = $aujourdhui->modify('+' . HORIZON_JOURS . ' days');
$erreurs    = [];

if ($prestationId && !$prestation) {
    $erreurs[] = 'Un des soins choisis n\'est plus disponible. Choisis-en un autre.';
}
if ($dateChoisie !== '' && !$jour) {
    $erreurs[] = 'Choisis une date entre aujourd\'hui et le ' . format_date_fr($dateMax) . '.';
}

// ---------- Enregistrement de la réservation ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $heure = $_POST['heure'] ?? '';
    $note  = mb_substr(trim($_POST['note'] ?? ''), 0, 255);

    if (!$prestation || !$jour) {
        $erreurs[] = 'Choisis un soin et une date.';
    } elseif (!preg_match('/^\d{2}:\d{2}$/', $heure)) {
        $erreurs[] = 'Choisis un créneau horaire.';
    } else {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            // On recalcule les créneaux en verrouillant : le créneau est-il toujours libre ?
            $libres = creneaux_disponibles($prestation, $jour, true, $praticienneId ?: null);

            if (!isset($libres[$heure])) {
                $pdo->rollBack();
                $erreurs[] = 'Ce créneau vient d\'être pris. Choisis-en un autre.';
            } else {
                $debut = new DateTimeImmutable($jour->format('Y-m-d') . ' ' . $heure);
                $fin   = $debut->modify('+' . (int) $prestation['duree_minutes'] . ' minutes');

                $stmt = $pdo->prepare(
                    'INSERT INTO reservations
                        (client_id, prestation_id, libelle, praticienne_id, debut, fin, prix_fcfa, acompte_fcfa, note_client)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $user['id'], $prestation['id'], $prestation['nom'], $libres[$heure],
                    $debut->format('Y-m-d H:i:s'), $fin->format('Y-m-d H:i:s'),
                    $prestation['prix_fcfa'], $prestation['acompte_fcfa'],
                    $note !== '' ? $note : null,
                ]);
                $reservationId = (int) $pdo->lastInsertId();
                enregistrer_soins_reservation($reservationId, $prestation);
                $pdo->commit();

                redirect(BASE_URL . '/paiement/initier?reservation=' . $reservationId);
            }
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $ex;
        }
    }
}

// ---------- Créneaux à afficher ----------
$creneaux  = null;
$fermeture = null;
if ($prestation && $jour) {
    $fermeture = fermeture_du_jour($jour);
    $creneaux  = creneaux_disponibles($prestation, $jour, false, $praticienneId ?: null);
}

$pageTitle  = 'Réserver';
$activePage = 'reserver';
require __DIR__ . '/../includes/header.php';
?>

<section class="entete-page">
    <h1>Réserver une séance</h1>
    <p class="discret">Choisis ton soin et ta date, puis un horaire libre.</p>
</section>

<?php if ($erreurs): ?>
    <div class="alerte alerte-erreur" role="alert">
        <ul><?php foreach ($erreurs as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<div class="resa-grille">
    <!-- Étape 1 : soin + date -->
    <form method="get" class="resa-bloc" id="form-choix">
        <h2><span class="num">1</span> Soins et date</h2>

        <div class="champ">
            <label for="prestation">Soin</label>
            <select id="prestation" name="prestation" required>
                <option value="">Choisir un soin…</option>
                <?php foreach ($soinsParCategorie as $categorie => $liste): ?>
                    <optgroup label="<?= e($categorie) ?>">
                        <?php foreach ($liste as $s): ?>
                            <option value="<?= (int) $s['id'] ?>" <?= $prestation && $prestation['id'] == $s['id'] ? 'selected' : '' ?>>
                                <?= e($s['nom']) ?> · <?= format_duree((int) $s['duree_minutes']) ?> · <?= format_fcfa((int) $s['prix_fcfa']) ?>
                            </option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endforeach; ?>
            </select>
        </div>

        <?php require __DIR__ . '/../includes/choix_soins.php'; ?>

        <div class="champ">
            <label for="date">Date</label>
            <input type="date" id="date" name="date" required
                   value="<?= e($jour ? $jour->format('Y-m-d') : '') ?>"
                   min="<?= $aujourdhui->format('Y-m-d') ?>" max="<?= $dateMax->format('Y-m-d') ?>">
        </div>

        <div class="champ">
            <label for="praticienne">Praticienne</label>
            <select id="praticienne" name="praticienne">
                <option value="0">Sans préférence</option>
                <?php foreach ($praticiennesActives as $pr): ?>
                    <option value="<?= (int) $pr['id'] ?>" <?= $praticienneId === (int) $pr['id'] ? 'selected' : '' ?>><?= e($pr['nom']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <button type="submit" class="btn">Voir les horaires libres</button>
    </form>

    <!-- Étape 2 : créneau -->
    <div class="resa-bloc">
        <h2><span class="num">2</span> Horaire</h2>

        <?php if ($creneaux === null): ?>
            <p class="discret">Choisis d'abord un soin et une date.</p>

        <?php elseif ($fermeture !== null): ?>
            <p class="alerte alerte-info">L'institut est fermé ce jour-là (<?= e($fermeture) ?>). Choisis une autre date.</p>

        <?php elseif (!$creneaux): ?>
            <p class="alerte alerte-info">Plus aucun horaire libre le <?= e(format_date_fr($jour)) ?> pour ce soin<?= $nomPraticienne ? ' avec ' . e($nomPraticienne) . '. Essaie « Sans préférence » ou' : '. Essaie' ?> une autre date.</p>

        <?php else: ?>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="prestation" value="<?= (int) $prestation['id'] ?>">
                <?php foreach ($prestation['autres'] as $idAutre): ?>
                    <input type="hidden" name="autres[]" value="<?= $idAutre ?>">
                <?php endforeach; ?>
                <input type="hidden" name="date" value="<?= e($jour->format('Y-m-d')) ?>">
                <input type="hidden" name="praticienne" value="<?= $praticienneId ?>">

                <p class="discret">Le <?= e(format_date_fr($jour)) ?> · <?= e($prestation['nom']) ?> (<?= format_duree((int) $prestation['duree_minutes']) ?>)
                    · <?= $nomPraticienne ? 'avec ' . e($nomPraticienne) : 'avec la praticienne disponible' ?></p>

                <?php foreach (grouper_creneaux($creneaux) as $moment => $heures): ?>
                    <fieldset class="groupe-creneaux">
                        <legend><?= e($moment) ?></legend>
                        <div class="creneaux">
                            <?php foreach ($heures as $heure): $idc = 'h' . str_replace(':', '', $heure); ?>
                                <input type="radio" name="heure" id="<?= $idc ?>" value="<?= e($heure) ?>"
                                       <?= ($_POST['heure'] ?? '') === $heure ? 'checked' : '' ?> required>
                                <label for="<?= $idc ?>"><?= e(str_replace(':', 'h', $heure)) ?></label>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>
                <?php endforeach; ?>

                <div class="champ">
                    <label for="note">Une précision ? (facultatif)</label>
                    <textarea id="note" name="note" rows="2" maxlength="255"
                              placeholder="Ex. peau sensible, couleur de vernis souhaitée…"><?= e($_POST['note'] ?? '') ?></textarea>
                </div>

                <div class="recap-prix">
                    <div><span><?= count($prestation['soins']) > 1 ? 'Prix des ' . count($prestation['soins']) . ' soins' : 'Prix du soin' ?></span><strong><?= format_fcfa((int) $prestation['prix_fcfa']) ?></strong></div>
                    <?php if ($prestation['acompte_fcfa'] > 0): ?>
                        <div class="discret"><span>Acompte en ligne (facultatif)</span><span><?= format_fcfa((int) $prestation['acompte_fcfa']) ?></span></div>
                    <?php endif; ?>
                    <div class="discret"><span>Tu choisis à l'étape suivante : acompte en ligne ou tout sur place.</span></div>
                </div>

                <?php require __DIR__ . '/../includes/conditions_resume.php'; ?>

                <button type="submit" class="btn btn-plein btn-large">Réserver ce créneau</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php';
