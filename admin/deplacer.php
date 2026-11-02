<?php
/**
 * L'admin déplace un rendez-vous confirmé (sans délai minimum, praticienne au choix).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/reservation.php';
require_once __DIR__ . '/../includes/notifications.php';
require_admin();

$id = (int) ($_POST['reservation'] ?? $_GET['reservation'] ?? 0);

$stmt = db()->prepare(
    'SELECT r.*, COALESCE(r.libelle, p.nom) AS soin, TIMESTAMPDIFF(MINUTE, r.debut, r.fin) AS duree_minutes, pr.nom AS praticienne, u.prenom, u.nom AS nom_client, u.email, u.telephone
     FROM reservations r
     JOIN prestations p   ON p.id  = r.prestation_id
     JOIN praticiennes pr ON pr.id = r.praticienne_id
     JOIN utilisateurs u  ON u.id  = r.client_id
     WHERE r.id = ?'
);
$stmt->execute([$id]);
$resa = $stmt->fetch();

if (!$resa || $resa['statut'] !== 'confirmee' || new DateTimeImmutable($resa['fin']) < new DateTimeImmutable()) {
    flash('erreur', 'Seul un rendez-vous confirmé, pas encore terminé, peut être déplacé.');
    redirect(ADMIN_URL . '/reservations.php');
}

const DELAI_ADMIN_DEPLACEMENT = -14;   // l'admin peut choisir le quart d'heure en cours
$praticiennesActives = db()->query('SELECT id, nom FROM praticiennes WHERE actif = 1 ORDER BY nom')->fetchAll();
$idsActives = array_map('intval', array_column($praticiennesActives, 'id'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $jour   = jour_reservable((string) ($_POST['date'] ?? ''));
    $heure  = (string) ($_POST['heure'] ?? '');
    $pratId = (int) ($_POST['praticienne'] ?? 0);
    $pratId = in_array($pratId, $idsActives, true) ? $pratId : 0;
    $retour = ADMIN_URL . '/deplacer.php?' . http_build_query(['reservation' => $id, 'date' => $_POST['date'] ?? '', 'praticienne' => $pratId]);

    if (!$jour || !preg_match('/^\d{2}:\d{2}$/', $heure)) {
        flash('erreur', 'Choisis une date et un horaire.');
        redirect($retour);
    }
    $ancien = deplacer_reservation($id, $jour, $heure, $pratId ?: null, DELAI_ADMIN_DEPLACEMENT);
    if ($ancien === null) {
        flash('erreur', 'Cet horaire vient d\'être pris. Choisis-en un autre.');
        redirect($retour);
    }
    $message = 'Rendez-vous de ' . $resa['prenom'] . ' ' . $resa['nom_client'] . ' déplacé au '
             . format_date_fr(new DateTimeImmutable($jour->format('Y-m-d') . ' ' . $heure), true) . '.';
    if (isset($_POST['prevenir']) && $resa['email']) {
        try {
            notifier_deplacement($id, $ancien, true);
            $message .= ' La cliente est prévenue par email.';
        } catch (Throwable $e) {
            error_log('[Emails] ' . $e->getMessage());
        }
    } elseif (!$resa['email'] || !isset($_POST['prevenir'])) {
        $message .= ' Pense à prévenir la cliente.';
    }
    flash('succes', $message);
    redirect(ADMIN_URL . '/reservations.php?du=' . $jour->format('Y-m-d') . '&au=' . $jour->format('Y-m-d'));
}

$dateMin   = new DateTimeImmutable('today');
$dateMax   = $dateMin->modify('+' . HORIZON_JOURS . ' days');
$jour      = jour_reservable((string) ($_GET['date'] ?? substr($resa['debut'], 0, 10)));
$pratId    = isset($_GET['praticienne']) ? (int) $_GET['praticienne'] : (int) $resa['praticienne_id'];
$pratId    = in_array($pratId, $idsActives, true) ? $pratId : 0;
$creneaux  = $jour ? creneaux_disponibles(['duree_minutes' => $resa['duree_minutes']], $jour, false, $pratId ?: null, DELAI_ADMIN_DEPLACEMENT, $id) : null;
$fermeture = $jour ? fermeture_du_jour($jour) : null;
$estAdmin  = true;

$pageTitle   = 'Déplacer un rendez-vous';
$activeAdmin = 'reservations';
require __DIR__ . '/../includes/admin_header.php';
?>

<section class="entete-page">
    <p><a href="<?= ADMIN_URL ?>/reservations.php">← Réservations</a></p>
    <h1>Déplacer un rendez-vous</h1>
</section>

<?php require __DIR__ . '/../includes/deplacement_form.php'; ?>

<?php require __DIR__ . '/../includes/admin_footer.php';
