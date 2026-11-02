<?php
/**
 * La cliente déplace un de ses rendez-vous confirmés (jusqu'à 24 h avant).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/reservation.php';
require_once __DIR__ . '/../includes/notifications.php';
require_login();

$client = current_user();
$id     = (int) ($_POST['reservation'] ?? $_GET['reservation'] ?? 0);

$stmt = db()->prepare(
    'SELECT r.*, COALESCE(r.libelle, p.nom) AS soin, TIMESTAMPDIFF(MINUTE, r.debut, r.fin) AS duree_minutes, pr.nom AS praticienne
     FROM reservations r
     JOIN prestations p   ON p.id  = r.prestation_id
     JOIN praticiennes pr ON pr.id = r.praticienne_id
     WHERE r.id = ? AND r.client_id = ?'
);
$stmt->execute([$id, $client['id']]);
$resa = $stmt->fetch();

if (!$resa || !deplacement_possible($resa)) {
    flash('erreur', 'Ce rendez-vous ne peut plus être déplacé en ligne (moins de ' . DELAI_ANNULATION_H
        . ' h avant, ou déjà annulé). Contacte directement l\'institut.');
    redirect(BASE_URL . '/profile.php');
}

$praticiennesActives = db()->query('SELECT id, nom FROM praticiennes WHERE actif = 1 ORDER BY nom')->fetchAll();
$idsActives = array_map('intval', array_column($praticiennesActives, 'id'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $jour   = jour_reservable((string) ($_POST['date'] ?? ''));
    $heure  = (string) ($_POST['heure'] ?? '');
    $pratId = (int) ($_POST['praticienne'] ?? 0);
    $pratId = in_array($pratId, $idsActives, true) ? $pratId : 0;
    $retour = BASE_URL . '/deplacer.php?' . http_build_query(['reservation' => $id, 'date' => $_POST['date'] ?? '', 'praticienne' => $pratId]);

    if (!$jour || !preg_match('/^\d{2}:\d{2}$/', $heure)) {
        flash('erreur', 'Choisis une date et un horaire.');
        redirect($retour);
    }
    $ancien = deplacer_reservation($id, $jour, $heure, $pratId ?: null, DELAI_MIN_MINUTES);
    if ($ancien === null) {
        flash('erreur', 'Cet horaire vient d\'être pris. Choisis-en un autre.');
        redirect($retour);
    }
    try {
        notifier_deplacement($id, $ancien, false);
    } catch (Throwable $e) {
        error_log('[Emails] ' . $e->getMessage());
    }
    flash('succes', 'Rendez-vous déplacé au ' . format_date_fr(new DateTimeImmutable($jour->format('Y-m-d') . ' ' . $heure), true) . '.');
    redirect(BASE_URL . '/profile.php');
}

// Par défaut : la date et la praticienne actuelles
$dateMin   = new DateTimeImmutable('today');
$dateMax   = $dateMin->modify('+' . HORIZON_JOURS . ' days');
$jour      = jour_reservable((string) ($_GET['date'] ?? substr($resa['debut'], 0, 10)));
$pratId    = isset($_GET['praticienne']) ? (int) $_GET['praticienne'] : (int) $resa['praticienne_id'];
$pratId    = in_array($pratId, $idsActives, true) ? $pratId : 0;
$creneaux  = $jour ? creneaux_disponibles(['duree_minutes' => $resa['duree_minutes']], $jour, false, $pratId ?: null, DELAI_MIN_MINUTES, $id) : null;
$fermeture = $jour ? fermeture_du_jour($jour) : null;
$estAdmin  = false;

$pageTitle  = 'Déplacer mon rendez-vous';
$activePage = 'compte';
require __DIR__ . '/../includes/header.php';
?>

<section class="entete-page">
    <p><a href="<?= BASE_URL ?>/profile.php">← Mon compte</a></p>
    <h1>Déplacer mon rendez-vous</h1>
</section>

<?php require __DIR__ . '/../includes/deplacement_form.php'; ?>

<?php require __DIR__ . '/../includes/footer.php';
