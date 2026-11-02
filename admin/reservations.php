<?php
/**
 * Liste des réservations avec filtres + actions (terminé, absente, annuler, remboursé).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin_actions.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    traiter_action_admin();
}

// ---------- Filtres (GET) ----------
$aujourdhui = new DateTimeImmutable('today');
$du     = DateTimeImmutable::createFromFormat('!Y-m-d', $_GET['du'] ?? '') ?: $aujourdhui;
$au     = DateTimeImmutable::createFromFormat('!Y-m-d', $_GET['au'] ?? '') ?: $aujourdhui->modify('+30 days');
$statut = $_GET['statut'] ?? '';
$praticienneId = (int) ($_GET['praticienne'] ?? 0);
$recherche = trim($_GET['q'] ?? '');

if ($au < $du) {
    [$du, $au] = [$au, $du];
}

$statuts = [
    ''          => 'Tous (hors non confirmées expirées)',
    'confirmee' => 'Confirmées',
    'a_cloturer'=> 'À clôturer (passées)',
    'terminee'  => 'Terminées',
    'absente'   => 'Absentes',
    'annulee'   => 'Annulées',
    'attente'   => 'À confirmer / expirées',
];
if (!array_key_exists($statut, $statuts)) {
    $statut = '';
}

$where  = ['r.debut >= ?', 'r.debut < ?'];
$params = [$du->format('Y-m-d 00:00:00'), $au->modify('+1 day')->format('Y-m-d 00:00:00')];

switch ($statut) {
    case '':
        $where[] = "(r.statut <> 'en_attente_paiement' OR " . sql_reservation_bloquante() . ')';
        break;
    case 'a_cloturer':
        $where[] = "r.statut = 'confirmee' AND r.fin < NOW()";
        break;
    case 'attente':
        $where[] = "r.statut = 'en_attente_paiement'";
        break;
    default:
        $where[]  = 'r.statut = ?';
        $params[] = $statut;
}
if ($praticienneId) {
    $where[]  = 'r.praticienne_id = ?';
    $params[] = $praticienneId;
}
if ($recherche !== '') {
    $like      = '%' . $recherche . '%';
    $criteres  = ['u.prenom LIKE ?', 'u.nom LIKE ?', 'u.email LIKE ?', 'CONCAT(u.prenom, \' \', u.nom) LIKE ?'];
    array_push($params, $like, $like, $like, $like);

    // Recherche par téléphone seulement si on a tapé au moins 3 chiffres (espaces ignorés)
    $chiffres = preg_replace('/\D/', '', $recherche);
    if (strlen($chiffres) >= 3) {
        $criteres[] = 'u.telephone LIKE ?';
        $params[]   = '%' . $chiffres . '%';
    }
    $where[] = '(' . implode(' OR ', $criteres) . ')';
}

$stmt = db()->prepare(sql_reservations_admin() . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY r.debut LIMIT 300');
$stmt->execute($params);
$lignes = $stmt->fetchAll();

// Totaux de la sélection (hors annulées)
$totalPrix = $totalPaye = 0;
foreach ($lignes as $l) {
    if (!in_array($l['statut'], ['annulee', 'en_attente_paiement'], true)) {
        $totalPrix += (int) $l['prix_fcfa'];
        $totalPaye += (int) $l['paye'];
    }
}

$praticiennes = db()->query('SELECT id, nom FROM praticiennes ORDER BY nom')->fetchAll();
$retour = $_SERVER['REQUEST_URI'];   // on revient sur la même liste filtrée après une action

$pageTitle   = 'Réservations';
$activeAdmin = 'reservations';
require __DIR__ . '/../includes/admin_header.php';
?>

<section class="entete-page entete-compte">
    <h1>Réservations</h1>
    <a href="<?= ADMIN_URL ?>/nouvelle-reservation.php" class="btn btn-plein">Nouveau rendez-vous</a>
</section>

<form method="get" class="filtres-admin">
    <div class="champ">
        <label for="du">Du</label>
        <input type="date" id="du" name="du" value="<?= $du->format('Y-m-d') ?>">
    </div>
    <div class="champ">
        <label for="au">Au</label>
        <input type="date" id="au" name="au" value="<?= $au->format('Y-m-d') ?>">
    </div>
    <div class="champ">
        <label for="statut">Statut</label>
        <select id="statut" name="statut">
            <?php foreach ($statuts as $val => $lib): ?>
                <option value="<?= e($val) ?>" <?= $val === $statut ? 'selected' : '' ?>><?= e($lib) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="champ">
        <label for="praticienne">Praticienne</label>
        <select id="praticienne" name="praticienne">
            <option value="0">Toutes</option>
            <?php foreach ($praticiennes as $p): ?>
                <option value="<?= (int) $p['id'] ?>" <?= $praticienneId === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['nom']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="champ champ-large">
        <label for="q">Cliente</label>
        <input type="search" id="q" name="q" value="<?= e($recherche) ?>" placeholder="Nom, téléphone ou email">
    </div>
    <div class="filtres-boutons">
        <button type="submit" class="btn btn-plein">Filtrer</button>
        <a href="<?= ADMIN_URL ?>/reservations.php" class="btn">Réinitialiser</a>
    </div>
</form>

<div class="raccourcis">
    <a href="?du=<?= $aujourdhui->format('Y-m-d') ?>&amp;au=<?= $aujourdhui->format('Y-m-d') ?>">Aujourd'hui</a>
    <a href="?du=<?= $aujourdhui->modify('+1 day')->format('Y-m-d') ?>&amp;au=<?= $aujourdhui->modify('+1 day')->format('Y-m-d') ?>">Demain</a>
    <a href="?du=<?= $aujourdhui->format('Y-m-d') ?>&amp;au=<?= $aujourdhui->modify('+6 days')->format('Y-m-d') ?>">7 prochains jours</a>
    <a href="?du=<?= $aujourdhui->modify('-60 days')->format('Y-m-d') ?>&amp;au=<?= $aujourdhui->format('Y-m-d') ?>&amp;statut=a_cloturer">À clôturer</a>
</div>

<p class="resume-liste">
    <strong><?= count($lignes) ?></strong> réservation<?= count($lignes) > 1 ? 's' : '' ?>
    du <?= $du->format('d/m/Y') ?> au <?= $au->format('d/m/Y') ?>
    <?php if ($totalPrix): ?>
        · <?= format_fcfa($totalPrix) ?> de soins, dont <?= format_fcfa($totalPaye) ?> d'acomptes payés en ligne
    <?php endif; ?>
    <?php if (count($lignes) === 300): ?><br><span class="discret">Affichage limité à 300 lignes : resserre les filtres.</span><?php endif; ?>
</p>

<?php if (!$lignes): ?>
    <p class="vide">Aucune réservation ne correspond à ces filtres.</p>
<?php else: ?>
    <?php afficher_tableau($lignes, $retour, true); ?>
<?php endif; ?>

<?php require __DIR__ . '/../includes/admin_footer.php'; ?>