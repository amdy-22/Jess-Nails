<?php
/**
 * Planning d'une praticienne : semaine type (jours, horaires, pause) et congés.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/reservation.php';
require_admin();

$jours = [1 => 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];

$stmt = db()->prepare('SELECT * FROM praticiennes WHERE id = ?');
$stmt->execute([(int) ($_GET['id'] ?? $_POST['id'] ?? 0)]);
$prat = $stmt->fetch();
if (!$prat) {
    flash('erreur', 'Praticienne introuvable.');
    redirect(ADMIN_URL . '/praticiennes.php');
}
$id      = (int) $prat['id'];
$urlPage = ADMIN_URL . '/planning-praticienne.php?id=' . $id;

/** Horaires de l'institut par jour : [n => ['09:00', '19:00'] ou null si fermé] */
$institut = [];
foreach (db()->query('SELECT * FROM horaires')->fetchAll() as $h) {
    $institut[(int) $h['jour_semaine']] = $h['heure_ouverture'] === null ? null
        : [substr($h['heure_ouverture'], 0, 5), substr($h['heure_fermeture'], 0, 5)];
}

/**
 * Rendez-vous confirmés à venir de cette praticienne qui tombent en dehors de son planning
 * (jour de repos, pause, congé, hors horaires). Sert à prévenir l'admin après une modification.
 */
function rdv_hors_planning(int $pratId): array
{
    $stmt = db()->prepare("SELECT id, debut, fin FROM reservations
                           WHERE praticienne_id = ? AND statut = 'confirmee' AND debut > NOW() ORDER BY debut");
    $stmt->execute([$pratId]);
    $horsPlanning = [];
    foreach ($stmt->fetchAll() as $r) {
        $debut = new DateTimeImmutable($r['debut']);
        $jour  = new DateTimeImmutable($debut->format('Y-m-d'));
        $pl    = planning_du_jour([$pratId], $jour, $jour->setTime(0, 0), $jour->setTime(23, 59));
        $d = strtotime($r['debut']);
        $f = strtotime($r['fin']);
        if (!isset($pl[$pratId])
            || $d < $pl[$pratId]['debut'] || $f > $pl[$pratId]['fin']
            || ($pl[$pratId]['pause'] && $d < $pl[$pratId]['pause'][1] && $f > $pl[$pratId]['pause'][0])) {
            $horsPlanning[] = $r;
        }
    }
    return $horsPlanning;
}

function prevenir_conflits(int $pratId, string $nom): void
{
    $conflits = rdv_hors_planning($pratId);
    if ($conflits) {
        $n = count($conflits);
        $premier = new DateTimeImmutable($conflits[0]['debut']);
        flash('info', 'Attention : ' . $n . ' rendez-vous confirmé' . ($n > 1 ? 's' : '') . ' de ' . $nom
            . ' tombe' . ($n > 1 ? 'nt' : '') . ' maintenant en dehors de son planning (le premier : '
            . format_date_fr($premier, true) . '). Déplace-les ou annule-les depuis la page Réservations.');
    }
}

// =====================================================
// Traitements
// =====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    // ---------- Semaine type ----------
    if ($action === 'semaine') {
        $erreurs = [];
        $lignes  = [];
        $hm = fn($v) => preg_match('/^\d{2}:\d{2}$/', (string) $v) ? $v : null;

        foreach ($jours as $n => $nomJour) {
            if (!isset($_POST['travaille'][$n])) {
                $lignes[$n] = [null, null, null, null];
                continue;
            }
            $deb = $hm($_POST['debut'][$n] ?? '');
            $fin = $hm($_POST['fin'][$n] ?? '');
            $pd  = $hm($_POST['pause_debut'][$n] ?? '');
            $pf  = $hm($_POST['pause_fin'][$n] ?? '');

            if (!$deb || !$fin || $fin <= $deb) {
                $erreurs[] = "$nomJour : l'heure de fin doit être après l'heure de début.";
                continue;
            }
            if ($institut[$n] === null) {
                $erreurs[] = "$nomJour : l'institut est fermé ce jour-là (voir la page Horaires).";
                continue;
            }
            if (($pd === null) !== ($pf === null)) {
                $erreurs[] = "$nomJour : indique le début ET la fin de la pause, ou aucun des deux.";
                continue;
            }
            if ($pd && ($pf <= $pd || $pd < $deb || $pf > $fin)) {
                $erreurs[] = "$nomJour : la pause doit être comprise dans ses heures de travail.";
                continue;
            }
            $lignes[$n] = [$deb, $fin, $pd, $pf];
        }

        if ($erreurs) {
            flash('erreur', implode(' ', $erreurs));
            redirect($urlPage);
        }

        $pdo = db();
        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM praticienne_horaires WHERE praticienne_id = ?')->execute([$id]);
        $ins = $pdo->prepare('INSERT INTO praticienne_horaires (praticienne_id, jour_semaine, heure_debut, heure_fin, pause_debut, pause_fin)
                              VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($lignes as $n => [$deb, $fin, $pd, $pf]) {
            $ins->execute([$id, $n, $deb, $fin, $pd, $pf]);
        }
        $pdo->commit();

        flash('succes', 'Planning de ' . $prat['nom'] . ' enregistré.');
        prevenir_conflits($id, $prat['nom']);
        redirect($urlPage);
    }

    // ---------- Revenir aux horaires de l'institut ----------
    if ($action === 'reinitialiser') {
        db()->prepare('DELETE FROM praticienne_horaires WHERE praticienne_id = ?')->execute([$id]);
        flash('succes', $prat['nom'] . ' suit de nouveau les horaires de l\'institut, sans pause.');
        redirect($urlPage);
    }

    // ---------- Ajouter un congé ----------
    if ($action === 'ajouter_absence') {
        $du    = DateTimeImmutable::createFromFormat('!Y-m-d', $_POST['du'] ?? '');
        $au    = DateTimeImmutable::createFromFormat('!Y-m-d', $_POST['au'] ?? '') ?: $du;
        $motif = trim($_POST['motif'] ?? '');
        if (!$du || !$au || $au < $du) {
            flash('erreur', 'Indique une date de début, et une date de fin égale ou postérieure.');
        } elseif ($au < new DateTimeImmutable('today')) {
            flash('erreur', 'Ce congé est entièrement passé.');
        } else {
            db()->prepare('INSERT INTO praticienne_absences (praticienne_id, date_debut, date_fin, motif) VALUES (?, ?, ?, ?)')
                ->execute([$id, $du->format('Y-m-d'), $au->format('Y-m-d'), $motif !== '' ? $motif : null]);
            flash('succes', 'Congé ajouté du ' . format_date_fr($du) . ' au ' . format_date_fr($au) . '.');
            prevenir_conflits($id, $prat['nom']);
        }
        redirect($urlPage . '#conges');
    }

    // ---------- Supprimer un congé ----------
    if ($action === 'supprimer_absence') {
        db()->prepare('DELETE FROM praticienne_absences WHERE id = ? AND praticienne_id = ?')->execute([(int) $_POST['absence'], $id]);
        flash('succes', 'Congé retiré : elle est de nouveau réservable ces jours-là.');
        redirect($urlPage . '#conges');
    }

    redirect($urlPage);
}

// =====================================================
// Affichage
// =====================================================
$planning = [];
$stmt = db()->prepare('SELECT * FROM praticienne_horaires WHERE praticienne_id = ?');
$stmt->execute([$id]);
foreach ($stmt->fetchAll() as $l) {
    $planning[(int) $l['jour_semaine']] = $l;
}
$personnalise = (bool) $planning;

$stmt = db()->prepare('SELECT * FROM praticienne_absences WHERE praticienne_id = ? AND date_fin >= CURDATE() ORDER BY date_debut');
$stmt->execute([$id]);
$absences = $stmt->fetchAll();

$conflits = rdv_hors_planning($id);

$pageTitle   = 'Planning de ' . $prat['nom'];
$activeAdmin = 'praticiennes';
require __DIR__ . '/../includes/admin_header.php';
?>

<section class="entete-page">
    <p><a href="<?= ADMIN_URL ?>/praticiennes.php">← Praticiennes</a></p>
    <h1>Planning de <?= e($prat['nom']) ?></h1>
    <p class="discret">
        <?= $personnalise
            ? 'Planning personnalisé. Les créneaux proposés aux clientes respectent ses jours, horaires, pause et congés.'
            : 'Elle suit pour l\'instant les horaires de l\'institut, sans pause. Coche ses jours et règle ses heures ci-dessous.' ?>
    </p>
</section>

<?php if ($conflits): ?>
    <div class="alerte alerte-info">
        <?= count($conflits) ?> rendez-vous confirmé<?= count($conflits) > 1 ? 's' : '' ?> à venir tombe<?= count($conflits) > 1 ? 'nt' : '' ?>
        en dehors de son planning actuel :
        <?php foreach (array_slice($conflits, 0, 5) as $c): ?>
            <a href="<?= ADMIN_URL ?>/reservations.php?du=<?= substr($c['debut'], 0, 10) ?>&amp;au=<?= substr($c['debut'], 0, 10) ?>&amp;praticienne=<?= $id ?>">
                <?= e(format_date_fr(new DateTimeImmutable($c['debut']), true)) ?></a><?= $c !== end($conflits) ? ',' : '.' ?>
        <?php endforeach; ?>
        <?= count($conflits) > 5 ? '…' : '' ?>
    </div>
<?php endif; ?>

<div class="grille-profil grille-admin">
    <form method="post" class="resa-bloc">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="semaine">
        <input type="hidden" name="id" value="<?= $id ?>">
        <h2>Semaine type</h2>

        <div class="planning-liste">
            <div class="planning-entete" aria-hidden="true">
                <span>Jour</span><span>Travail</span><span>Pause (facultatif)</span>
            </div>
            <?php foreach ($jours as $n => $nomJour):
                $l      = $planning[$n] ?? null;
                $ferme  = $institut[$n] === null;
                $coche  = !$ferme && ($personnalise ? ($l && $l['heure_debut'] !== null) : true);
                $deb    = $l && $l['heure_debut'] ? substr($l['heure_debut'], 0, 5) : ($institut[$n][0] ?? '09:00');
                $fin    = $l && $l['heure_fin']   ? substr($l['heure_fin'], 0, 5)   : ($institut[$n][1] ?? '19:00');
                $pd     = $l && $l['pause_debut'] ? substr($l['pause_debut'], 0, 5) : '';
                $pf     = $l && $l['pause_fin']   ? substr($l['pause_fin'], 0, 5)   : ''; ?>
                <div class="planning-ligne">
                    <label class="case">
                        <input type="checkbox" name="travaille[<?= $n ?>]" value="1" <?= $coche ? 'checked' : '' ?> <?= $ferme ? 'disabled' : '' ?> data-planning-case>
                        <?= $nomJour ?>
                    </label>
                    <?php if ($ferme): ?>
                        <span class="discret planning-ferme-institut">Institut fermé</span>
                    <?php else: ?>
                        <div class="planning-heures">
                            <label class="sr-only" for="d<?= $n ?>">Début <?= $nomJour ?></label>
                            <input type="time" id="d<?= $n ?>" name="debut[<?= $n ?>]" value="<?= $deb ?>" step="900" min="<?= $institut[$n][0] ?>" max="<?= $institut[$n][1] ?>">
                            <span class="discret">à</span>
                            <label class="sr-only" for="f<?= $n ?>">Fin <?= $nomJour ?></label>
                            <input type="time" id="f<?= $n ?>" name="fin[<?= $n ?>]" value="<?= $fin ?>" step="900" min="<?= $institut[$n][0] ?>" max="<?= $institut[$n][1] ?>">
                        </div>
                        <div class="planning-heures">
                            <label class="sr-only" for="pd<?= $n ?>">Début de pause <?= $nomJour ?></label>
                            <input type="time" id="pd<?= $n ?>" name="pause_debut[<?= $n ?>]" value="<?= $pd ?>" step="900">
                            <span class="discret">à</span>
                            <label class="sr-only" for="pf<?= $n ?>">Fin de pause <?= $nomJour ?></label>
                            <input type="time" id="pf<?= $n ?>" name="pause_fin[<?= $n ?>]" value="<?= $pf ?>" step="900">
                        </div>
                        <span class="planning-repos">Repos</span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <button type="submit" class="btn btn-plein">Enregistrer le planning</button>
        <p class="discret petit-note">Ses heures restent toujours comprises dans celles de l'institut. Laisse la pause vide si elle n'en prend pas.</p>
    </form>

    <div class="colonne-droite">
        <section class="resa-bloc" id="conges">
            <h2>Congés</h2>
            <form method="post" class="form-conge">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="ajouter_absence">
                <input type="hidden" name="id" value="<?= $id ?>">
                <div class="ligne-2">
                    <div class="champ"><label for="du">Du</label><input type="date" id="du" name="du" min="<?= date('Y-m-d') ?>" required></div>
                    <div class="champ"><label for="au">Au (inclus)</label><input type="date" id="au" name="au" min="<?= date('Y-m-d') ?>"></div>
                </div>
                <div class="champ"><label for="motif">Motif (visible par l'admin seulement)</label><input type="text" id="motif" name="motif" maxlength="150" placeholder="Ex. Congé, formation…"></div>
                <button type="submit" class="btn">Ajouter le congé</button>
            </form>

            <?php if (!$absences): ?>
                <p class="vide">Aucun congé prévu.</p>
            <?php else: ?>
                <div class="liste-simple">
                    <?php foreach ($absences as $a):
                        $du = new DateTimeImmutable($a['date_debut']); $au = new DateTimeImmutable($a['date_fin']); ?>
                        <div class="ligne-simple">
                            <div>
                                <strong><?= $du == $au ? e(ucfirst(format_date_fr($du))) : 'Du ' . e(format_date_fr($du)) . ' au ' . e(format_date_fr($au)) ?></strong>
                                <?php if ($a['motif']): ?><br><span class="discret"><?= e($a['motif']) ?></span><?php endif; ?>
                            </div>
                            <form method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="supprimer_absence">
                                <input type="hidden" name="id" value="<?= $id ?>">
                                <input type="hidden" name="absence" value="<?= (int) $a['id'] ?>">
                                <button type="submit" class="btn-action btn-action-danger">Retirer</button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <?php if ($personnalise): ?>
            <form method="post" class="resa-bloc" data-confirmer="<?= e($prat['nom']) ?> suivra de nouveau les horaires de l'institut, sans pause. Continuer ?">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="reinitialiser">
                <input type="hidden" name="id" value="<?= $id ?>">
                <p class="discret">Supprimer son planning personnalisé et revenir aux horaires de l'institut.</p>
                <button type="submit" class="btn-action">Revenir aux horaires de l'institut</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/../includes/admin_footer.php';
