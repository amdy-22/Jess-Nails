<?php
/**
 * Horaires d'ouverture hebdomadaires + fermetures exceptionnelles.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/reservation.php';
require_admin();

$urlPage = ADMIN_URL . '/horaires';
$jours   = [1 => 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    // ---------- Horaires de la semaine ----------
    if ($action === 'horaires') {
        $erreurs = [];
        $lignes  = [];
        foreach ($jours as $n => $nomJour) {
            $ouvert = isset($_POST['ouvert'][$n]);
            $ouv    = $_POST['ouverture'][$n] ?? '';
            $ferm   = $_POST['fermeture'][$n] ?? '';
            if ($ouvert) {
                if (!preg_match('/^\d{2}:\d{2}$/', $ouv) || !preg_match('/^\d{2}:\d{2}$/', $ferm)) {
                    $erreurs[] = $nomJour . ' : indique une heure d\'ouverture et de fermeture.';
                } elseif ($ferm <= $ouv) {
                    $erreurs[] = $nomJour . ' : la fermeture doit être après l\'ouverture.';
                }
            }
            $lignes[$n] = $ouvert ? [$ouv, $ferm] : [null, null];
        }

        if ($erreurs) {
            flash('erreur', implode(' ', $erreurs));
        } else {
            $maj = db()->prepare(
                'INSERT INTO horaires (jour_semaine, heure_ouverture, heure_fermeture) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE heure_ouverture = VALUES(heure_ouverture), heure_fermeture = VALUES(heure_fermeture)'
            );
            foreach ($lignes as $n => [$o, $f]) {
                $maj->execute([$n, $o, $f]);
            }
            flash('succes', 'Horaires enregistrés. Les rendez-vous déjà pris ne sont pas modifiés.');
        }
        redirect($urlPage);
    }

    // ---------- Ajouter une fermeture ----------
    if ($action === 'ajouter_fermeture') {
        $date  = DateTimeImmutable::createFromFormat('!Y-m-d', $_POST['date'] ?? '');
        $motif = trim($_POST['motif'] ?? '');
        if (!$date || $date < new DateTimeImmutable('today')) {
            flash('erreur', 'Choisis une date à venir.');
            redirect($urlPage . '#fermetures');
        }
        $existe = db()->prepare('SELECT COUNT(*) FROM fermetures WHERE date = ?');
        $existe->execute([$date->format('Y-m-d')]);
        if ((int) $existe->fetchColumn() > 0) {
            flash('erreur', 'Ce jour est déjà marqué comme fermé.');
            redirect($urlPage . '#fermetures');
        }
        db()->prepare('INSERT INTO fermetures (date, motif) VALUES (?, ?)')
            ->execute([$date->format('Y-m-d'), $motif !== '' ? $motif : null]);

        // Rendez-vous déjà confirmés ce jour-là ?
        $nb = db()->prepare("SELECT COUNT(*) FROM reservations WHERE DATE(debut) = ? AND statut = 'confirmee'");
        $nb->execute([$date->format('Y-m-d')]);
        $n = (int) $nb->fetchColumn();
        if ($n > 0) {
            flash('info', 'Fermeture ajoutée, mais ' . $n . ' rendez-vous confirmé' . ($n > 1 ? 's sont' : ' est')
                . ' prévu' . ($n > 1 ? 's' : '') . ' ce jour-là. Annule-les depuis la page Réservations pour prévenir les clientes.');
        } else {
            flash('succes', 'Fermeture du ' . format_date_fr($date) . ' ajoutée.');
        }
        redirect($urlPage . '#fermetures');
    }

    // ---------- Supprimer une fermeture ----------
    if ($action === 'supprimer_fermeture') {
        db()->prepare('DELETE FROM fermetures WHERE id = ?')->execute([(int) $_POST['id']]);
        flash('succes', 'Fermeture retirée : le jour est de nouveau réservable.');
        redirect($urlPage . '#fermetures');
    }

    redirect($urlPage);
}

$horaires = [];
foreach (db()->query('SELECT * FROM horaires')->fetchAll() as $h) {
    $horaires[(int) $h['jour_semaine']] = $h;
}

$fermetures = db()->query(
    "SELECT f.*, (SELECT COUNT(*) FROM reservations r WHERE DATE(r.debut) = f.date AND r.statut = 'confirmee') AS nb_rdv
     FROM fermetures f WHERE f.date >= CURDATE() ORDER BY f.date"
)->fetchAll();

$pageTitle   = 'Horaires';
$activeAdmin = 'horaires';
require __DIR__ . '/../includes/admin_header.php';
?>

<section class="entete-page">
    <h1>Horaires et fermetures</h1>
    <p class="discret">Les créneaux proposés aux clientes sont calculés à partir de ces horaires.</p>
</section>

<div class="grille-profil grille-admin">
    <form method="post" class="resa-bloc">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="horaires">
        <h2>Semaine type</h2>

        <div class="horaires-liste">
            <?php foreach ($jours as $n => $nomJour):
                $h = $horaires[$n] ?? null;
                $ouvert = $h && $h['heure_ouverture'] !== null; ?>
                <div class="horaire-ligne" data-horaire>
                    <label class="case horaire-jour">
                        <input type="checkbox" name="ouvert[<?= $n ?>]" value="1" <?= $ouvert ? 'checked' : '' ?> data-horaire-case>
                        <?= $nomJour ?>
                    </label>
                    <label class="sr-only" for="o<?= $n ?>">Ouverture <?= $nomJour ?></label>
                    <input type="time" id="o<?= $n ?>" name="ouverture[<?= $n ?>]" step="900"
                           value="<?= $ouvert ? substr($h['heure_ouverture'], 0, 5) : '09:00' ?>">
                    <span class="discret">à</span>
                    <label class="sr-only" for="f<?= $n ?>">Fermeture <?= $nomJour ?></label>
                    <input type="time" id="f<?= $n ?>" name="fermeture[<?= $n ?>]" step="900"
                           value="<?= $ouvert ? substr($h['heure_fermeture'], 0, 5) : '19:00' ?>">
                    <span class="horaire-ferme">Fermé</span>
                </div>
            <?php endforeach; ?>
        </div>

        <button type="submit" class="btn btn-plein">Enregistrer les horaires</button>
        <p class="discret petit-note">La dernière séance commence au plus tard à « fermeture − durée du soin ».</p>
    </form>

    <section class="resa-bloc" id="fermetures">
        <h2>Fermetures exceptionnelles</h2>
        <p class="discret petit-note">Jours fériés, congés, formation… Aucun créneau n'est proposé ces jours-là.</p>

        <form method="post" class="form-fermeture">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="ajouter_fermeture">
            <div class="champ">
                <label for="date">Date</label>
                <input type="date" id="date" name="date" min="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="champ">
                <label for="motif">Motif (affiché aux clientes)</label>
                <input type="text" id="motif" name="motif" maxlength="150" placeholder="Ex. Fête de l'Indépendance">
            </div>
            <button type="submit" class="btn">Ajouter</button>
        </form>

        <?php if (!$fermetures): ?>
            <p class="vide">Aucune fermeture prévue.</p>
        <?php else: ?>
            <div class="liste-simple">
                <?php foreach ($fermetures as $f): $d = new DateTimeImmutable($f['date']); ?>
                    <div class="ligne-simple">
                        <div>
                            <strong><?= e(ucfirst(format_date_fr($d))) ?></strong>
                            <?php if ($f['motif']): ?><br><span class="discret"><?= e($f['motif']) ?></span><?php endif; ?>
                            <?php if ($f['nb_rdv'] > 0): ?>
                                <br><a class="badge badge-attente" href="<?= ADMIN_URL ?>/reservations?du=<?= $f['date'] ?>&amp;au=<?= $f['date'] ?>&amp;statut=confirmee">
                                    <?= (int) $f['nb_rdv'] ?> rdv à annuler</a>
                            <?php endif; ?>
                        </div>
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="supprimer_fermeture">
                            <input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
                            <button type="submit" class="btn-action btn-action-danger">Retirer</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>

<?php require __DIR__ . '/../includes/admin_footer.php'; ?>