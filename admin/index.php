<?php
/**
 * Tableau de bord admin : chiffres du mois, rendez-vous du jour, points à traiter.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin_actions.php';
require_once __DIR__ . '/../includes/rappels_whatsapp.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['rappel_whatsapp'])) {
        traiter_rappel_whatsapp();
    }
    traiter_action_admin();
}

$debutMois = (new DateTimeImmutable('first day of this month'))->setTime(0, 0);
$finMois   = $debutMois->modify('+1 month');
$plage     = [$debutMois->format('Y-m-d H:i:s'), $finMois->format('Y-m-d H:i:s')];

// ---------- Chiffres ----------
function valeur(string $sql, array $params = []): int
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

$nbAujourdhui = valeur("SELECT COUNT(*) FROM reservations WHERE DATE(debut) = CURDATE() AND statut IN ('confirmee','terminee','absente')");
$nb7Jours     = valeur("SELECT COUNT(*) FROM reservations WHERE statut = 'confirmee' AND debut >= NOW() AND debut < NOW() + INTERVAL 7 DAY");
$caRealise    = valeur("SELECT COALESCE(SUM(prix_fcfa), 0) FROM reservations WHERE statut = 'terminee' AND debut >= ? AND debut < ?", $plage);
$caPrevu      = valeur("SELECT COALESCE(SUM(prix_fcfa), 0) FROM reservations WHERE statut = 'confirmee' AND debut >= NOW() AND debut < ?", [$plage[1]]);
$enLigne      = valeur("SELECT COALESCE(SUM(montant_fcfa), 0) FROM paiements WHERE statut = 'approuve' AND created_at >= ? AND created_at < ?", $plage);
$absencesMois = valeur("SELECT COUNT(*) FROM reservations WHERE statut = 'absente' AND debut >= ? AND debut < ?", $plage);
$annulMois    = valeur("SELECT COUNT(*) FROM reservations WHERE statut = 'annulee' AND debut >= ? AND debut < ?", $plage);

// ---------- Rendez-vous du jour ----------
$stmt = db()->query(sql_reservations_admin() . "
    WHERE DATE(r.debut) = CURDATE() AND r.statut IN ('confirmee','terminee','absente')
    ORDER BY r.debut");
$duJour = $stmt->fetchAll();

$rappelsWhatsapp = rappels_whatsapp_du_moment();

// ---------- À traiter ----------
$aCloturer = db()->query(sql_reservations_admin() . "
    WHERE r.statut = 'confirmee' AND r.fin < NOW() AND DATE(r.debut) < CURDATE()
    ORDER BY r.debut")->fetchAll();

$aRembourser = db()->query(sql_reservations_admin() . "
    WHERE r.statut = 'annulee'
      AND EXISTS (SELECT 1 FROM paiements pa WHERE pa.reservation_id = r.id AND pa.statut = 'approuve')
    ORDER BY r.debut")->fetchAll();

$retour = ADMIN_URL . '/';
$moisNom = explode(' ', format_date_fr($debutMois))[2];

$pageTitle   = 'Tableau de bord';
$activeAdmin = 'tableau';
require __DIR__ . '/../includes/admin_header.php';
?>

<section class="entete-page entete-compte">
    <div>
        <h1>Tableau de bord</h1>
        <p class="discret"><?= e(ucfirst(format_date_fr(new DateTimeImmutable()))) ?></p>
    </div>
    <div class="actions">
        <a href="<?= ADMIN_URL ?>/nouvelle-reservation" class="btn btn-plein">Nouveau rendez-vous</a>
        <a href="<?= ADMIN_URL ?>/reservations" class="btn">Toutes les réservations</a>
    </div>
</section>

<section class="tuiles">
    <div class="tuile">
        <span class="tuile-libelle">Rendez-vous aujourd'hui</span>
        <span class="tuile-valeur"><?= $nbAujourdhui ?></span>
        <span class="tuile-detail"><?= $nb7Jours ?> sur les 7 prochains jours</span>
    </div>
    <div class="tuile">
        <span class="tuile-libelle">Réalisé en <?= e($moisNom) ?></span>
        <span class="tuile-valeur"><?= format_fcfa($caRealise) ?></span>
        <span class="tuile-detail">soins marqués terminés</span>
    </div>
    <div class="tuile">
        <span class="tuile-libelle">Prévu d'ici la fin du mois</span>
        <span class="tuile-valeur"><?= format_fcfa($caPrevu) ?></span>
        <span class="tuile-detail">rendez-vous confirmés à venir</span>
    </div>
    <div class="tuile">
        <span class="tuile-libelle">Encaissé en ligne en <?= e($moisNom) ?></span>
        <span class="tuile-valeur"><?= format_fcfa($enLigne) ?></span>
        <span class="tuile-detail"><?= $absencesMois ?> absence<?= $absencesMois > 1 ? 's' : '' ?> · <?= $annulMois ?> annulation<?= $annulMois > 1 ? 's' : '' ?> ce mois</span>
    </div>
</section>

<?php afficher_rappels_whatsapp($rappelsWhatsapp, ADMIN_URL . '/'); ?>

<?php if ($aCloturer || $aRembourser): ?>
<section class="bloc-compte">
    <h2>À traiter</h2>
    <?php if ($aCloturer): ?>
        <p class="discret">Rendez-vous passés à marquer « terminé » ou « absente » :</p>
        <?php afficher_tableau($aCloturer, $retour, true); ?>
    <?php endif; ?>
    <?php if ($aRembourser): ?>
        <p class="discret">Réservations annulées dont l'acompte est à rembourser :</p>
        <?php afficher_tableau($aRembourser, $retour, true); ?>
    <?php endif; ?>
</section>
<?php endif; ?>

<section class="bloc-compte">
    <h2>Aujourd'hui</h2>
    <?php if (!$duJour): ?>
        <p class="vide">Aucun rendez-vous aujourd'hui.</p>
    <?php else: ?>
        <?php afficher_tableau($duJour, $retour, false); ?>
    <?php endif; ?>
</section>

<script>
// Le bloc « Rappels WhatsApp » se met à jour tout seul (chaque minute, et juste après un clic sur « Envoyer »)
(function () {
    function actualiser() {
        if (document.hidden) { return; }
        fetch(location.href, { credentials: 'same-origin' })
            .then(function (r) { return r.text(); })
            .then(function (html) {
                var neuf = new DOMParser().parseFromString(html, 'text/html').getElementById('rappels-whatsapp');
                var actuel = document.getElementById('rappels-whatsapp');
                if (neuf && actuel) { actuel.replaceWith(neuf); }
                // la pastille du menu suit aussi
                var menuNeuf = new DOMParser().parseFromString(html, 'text/html').querySelector('.nav-liens li');
                var menu = document.querySelector('.nav-liens li');
                if (menuNeuf && menu) { menu.innerHTML = menuNeuf.innerHTML; }
            })
            .catch(function () {});
    }
    setInterval(actualiser, 60000);
    document.addEventListener('submit', function (e) {
        if (e.target.classList && e.target.classList.contains('form-rappel')) { setTimeout(actualiser, 2000); }
    });
})();
</script>

<?php require __DIR__ . '/../includes/admin_footer.php';
