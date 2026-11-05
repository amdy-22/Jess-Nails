<?php
/**
 * Statistiques : chiffre d'affaires, fréquentation, soins les plus demandés, jours et heures chargés.
 * Période au choix, comparée à la période précédente. Export CSV des rendez-vous de la période.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/reservation.php';
require_once __DIR__ . '/../includes/statistiques.php';
require_admin();

$periode = stats_periode($_GET);
$debut   = $periode['debut'];
$fin     = $periode['fin'];

$STATUTS = ['confirmee' => 'Confirmée', 'terminee' => 'Terminée', 'absente' => 'Absente', 'annulee' => 'Annulée'];
$SOURCES = ['en_ligne' => 'Site (en ligne)', 'telephone' => 'Téléphone', 'whatsapp' => 'WhatsApp', 'sur_place' => 'Sur place'];

// ---------- Export CSV ----------
if (($_GET['export'] ?? '') === 'csv') {
    $stmt = db()->prepare(
        "SELECT r.debut, u.prenom, u.nom, u.telephone, COALESCE(r.libelle, p.nom) AS soins, pr.nom AS praticienne,
                r.statut, r.source, r.prix_fcfa, r.acompte_fcfa
         FROM reservations r
         JOIN utilisateurs u ON u.id = r.client_id
         JOIN prestations p ON p.id = r.prestation_id
         JOIN praticiennes pr ON pr.id = r.praticienne_id
         WHERE r.debut >= ? AND r.debut < ? AND r.statut <> 'en_attente_paiement'
         ORDER BY r.debut"
    );
    $stmt->execute([$debut->format('Y-m-d H:i:s'), $fin->format('Y-m-d H:i:s')]);

    $nomFichier = 'rendez-vous_' . $debut->format('Y-m-d') . '_' . $fin->modify('-1 day')->format('Y-m-d') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $nomFichier . '"');
    header('X-Content-Type-Options: nosniff');
    echo "\xEF\xBB\xBF";   // BOM : Excel reconnaît l'UTF-8 (accents)
    $sortie = fopen('php://output', 'w');
    fputcsv($sortie, ['Date', 'Heure', 'Cliente', 'Téléphone', 'Soins', 'Praticienne', 'Statut', 'Origine', 'Prix (FCFA)', 'Acompte (FCFA)'], ';');
    foreach ($stmt as $r) {
        $quand = new DateTimeImmutable($r['debut']);
        fputcsv($sortie, array_map('stats_cellule_csv', [
            $quand->format('d/m/Y'), $quand->format('H:i'), trim($r['prenom'] . ' ' . $r['nom']), $r['telephone'],
            $r['soins'], $r['praticienne'], $STATUTS[$r['statut']] ?? $r['statut'], $SOURCES[$r['source']] ?? $r['source'],
            $r['prix_fcfa'], $r['acompte_fcfa'],
        ]), ';');
    }
    fclose($sortie);
    exit;
}

// ---------- Calculs ----------
$s    = stats_calculer($debut, $fin);
$prec = stats_calculer($periode['debut_prec'], $periode['fin_prec']);
[$modeSerie, $serie] = stats_serie($s['par_jour'], $debut, $fin);
$topSoins = stats_top_soins($debut, $fin);

$noms = db()->query('SELECT id, nom FROM praticiennes')->fetchAll(PDO::FETCH_KEY_PAIR);
$parPraticienne = [];
foreach ($s['par_praticienne'] as $id => $v) {
    $parPraticienne[] = ['nom' => $noms[$id] ?? ('Praticienne ' . $id), 'valeur' => $v['ca'], 'texte' => format_fcfa($v['ca']) . ' · ' . $v['nb'] . ' RDV'];
}
usort($parPraticienne, fn ($a, $b) => $b['valeur'] <=> $a['valeur']);

$parSource = [];
foreach ($s['par_source'] as $src => $nb) {
    $parSource[] = ['nom' => $SOURCES[$src] ?? $src, 'valeur' => $nb, 'texte' => $nb . ' RDV · ' . round($nb / max(1, $s['nb_valides']) * 100) . ' %'];
}
usort($parSource, fn ($a, $b) => $b['valeur'] <=> $a['valeur']);

$classementSoins = array_map(fn ($t) => [
    'nom' => $t['nom'], 'valeur' => (int) $t['nb'], 'texte' => $t['nb'] . ' × · ' . format_fcfa((int) $t['ca']),
], $topSoins);

// Graphiques en colonnes
$pointsCA = array_map(fn ($p) => [
    'etiquette' => $p['etiquette'], 'valeur' => $p['ca'], 'titre' => $p['titre'],
    'detail' => format_fcfa($p['ca']) . ' · ' . $p['nb'] . ' RDV',
], $serie);

$joursNoms = [1 => 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];
$pointsJours = [];
foreach ($s['par_semaine_jour'] as $n => $nb) {
    $pointsJours[] = ['etiquette' => mb_substr($joursNoms[$n], 0, 3) . '.', 'valeur' => $nb, 'titre' => ucfirst($joursNoms[$n]), 'detail' => $nb . ' RDV'];
}
$pointsHeures = [];
if ($s['par_heure']) {
    for ($h = min(array_keys($s['par_heure'])); $h <= max(array_keys($s['par_heure'])); $h++) {
        $nb = $s['par_heure'][$h] ?? 0;
        $pointsHeures[] = ['etiquette' => $h . 'h', 'valeur' => $nb, 'titre' => 'Début à ' . $h . 'h', 'detail' => $nb . ' RDV'];
    }
}

/** Tableau équivalent à un graphique, pour qui préfère lire les chiffres. */
function stats_tableau(array $points, string $colonne, bool $fcfa = false): string
{
    $html = '<div class="tableau-conteneur"><table class="tableau"><thead><tr><th>Période</th><th>' . e($colonne) . '</th></tr></thead><tbody>';
    foreach ($points as $p) {
        $html .= '<tr><td>' . e($p['titre'] ?? $p['etiquette']) . '</td><td>' . e($p['detail'] ?? (string) $p['valeur']) . '</td></tr>';
    }
    return $html . '</tbody></table></div>';
}

$libelleSerie = ['jour' => 'par jour', 'semaine' => 'par semaine', 'mois' => 'par mois'][$modeSerie];
$libellePeriode = stats_libelle_periode($debut, $fin);
$urlBase = ADMIN_URL . '/statistiques';
$parametres = $periode['cle'] === 'perso'
    ? ['periode' => 'perso', 'du' => $debut->format('Y-m-d'), 'au' => $fin->modify('-1 day')->format('Y-m-d')]
    : ['periode' => $periode['cle']];

$pageTitle   = 'Statistiques';
$activeAdmin = 'statistiques';
require __DIR__ . '/../includes/admin_header.php';
?>

<section class="entete-page entete-compte">
    <div>
        <h1>Statistiques</h1>
        <p class="discret"><?= e($periode['libelle']) ?> : <?= e($libellePeriode) ?></p>
    </div>
    <div class="actions">
        <a href="<?= e($urlBase . '?' . http_build_query($parametres + ['export' => 'csv'])) ?>" class="btn">Exporter en CSV</a>
    </div>
</section>

<nav class="filtres filtres-stats" aria-label="Période">
    <?php foreach ($periode['choix'] as $cle => $lib): if ($cle === 'perso') { continue; } ?>
        <a class="pastille<?= $periode['cle'] === $cle ? ' pastille-active' : '' ?>" href="<?= e($urlBase . '?periode=' . $cle) ?>"
           <?= $periode['cle'] === $cle ? 'aria-current="true"' : '' ?>><?= e($lib) ?></a>
    <?php endforeach; ?>
</nav>

<form method="get" class="filtres-admin filtres-dates">
    <input type="hidden" name="periode" value="perso">
    <div class="champ">
        <label for="du">Du</label>
        <input type="date" id="du" name="du" value="<?= $debut->format('Y-m-d') ?>" required>
    </div>
    <div class="champ">
        <label for="au">Au</label>
        <input type="date" id="au" name="au" value="<?= $fin->modify('-1 day')->format('Y-m-d') ?>" required>
    </div>
    <div class="filtres-boutons"><button type="submit" class="btn">Afficher</button></div>
</form>

<p class="discret petit-note note-comparaison">Les variations (▲ ▼) comparent avec <?= e(stats_libelle_periode($periode['debut_prec'], $periode['fin_prec'])) ?>.</p>
<section class="tuiles tuiles-stats" aria-label="Chiffres clés">
    <div class="tuile">
        <span class="tuile-libelle">Chiffre d'affaires</span>
        <span class="tuile-valeur"><?= format_fcfa($s['ca']) ?></span>
        <span class="tuile-detail"><?= format_fcfa($s['ca_realise']) ?> réalisé · <?= format_fcfa($s['ca'] - $s['ca_realise']) ?> à venir</span>
        <span class="tuile-variation"><?= e(stats_variation($s['ca'], $prec['ca'])) ?></span>
    </div>
    <div class="tuile">
        <span class="tuile-libelle">Rendez-vous</span>
        <span class="tuile-valeur"><?= $s['nb_valides'] ?></span>
        <span class="tuile-detail"><?= $s['nb_terminees'] ?> terminé<?= $s['nb_terminees'] > 1 ? 's' : '' ?> · <?= $s['nb_valides'] - $s['nb_terminees'] ?> confirmé<?= ($s['nb_valides'] - $s['nb_terminees']) > 1 ? 's' : '' ?></span>
        <span class="tuile-variation"><?= e(stats_variation($s['nb_valides'], $prec['nb_valides'])) ?></span>
    </div>
    <div class="tuile">
        <span class="tuile-libelle">Panier moyen</span>
        <span class="tuile-valeur"><?= $s['panier_moyen'] === null ? '–' : format_fcfa($s['panier_moyen']) ?></span>
        <span class="tuile-detail">prix moyen d'un rendez-vous</span>
        <span class="tuile-variation"><?= e(stats_variation($s['panier_moyen'], $prec['panier_moyen'])) ?></span>
    </div>
    <div class="tuile">
        <span class="tuile-libelle">Taux d'annulation</span>
        <span class="tuile-valeur"><?= e(stats_pct($s['taux_annulation'])) ?></span>
        <span class="tuile-detail"><?= $s['nb_annulees'] ?> annulation<?= $s['nb_annulees'] > 1 ? 's' : '' ?></span>
        <span class="tuile-variation"><?= e(stats_variation($s['taux_annulation'], $prec['taux_annulation'], true)) ?></span>
    </div>
    <div class="tuile">
        <span class="tuile-libelle">Taux d'absence</span>
        <span class="tuile-valeur"><?= e(stats_pct($s['taux_absence'])) ?></span>
        <span class="tuile-detail"><?= $s['nb_absentes'] ?> absence<?= $s['nb_absentes'] > 1 ? 's' : '' ?> sur les rendez-vous clôturés</span>
        <span class="tuile-variation"><?= e(stats_variation($s['taux_absence'], $prec['taux_absence'], true)) ?></span>
    </div>
    <div class="tuile">
        <span class="tuile-libelle">Clientes reçues</span>
        <span class="tuile-valeur"><?= $s['nb_clientes'] ?></span>
        <span class="tuile-detail"><?= $s['nb_deja_venues'] ?> déjà venue<?= $s['nb_deja_venues'] > 1 ? 's' : '' ?> avant (<?= e(stats_pct($s['part_fidelite'])) ?>) · <?= $s['nouvelles_clientes'] ?> nouveau<?= $s['nouvelles_clientes'] > 1 ? 'x' : '' ?> compte<?= $s['nouvelles_clientes'] > 1 ? 's' : '' ?></span>
        <span class="tuile-variation"><?= e(stats_variation($s['nb_clientes'], $prec['nb_clientes'])) ?></span>
    </div>
    <div class="tuile">
        <span class="tuile-libelle">Acomptes encaissés en ligne</span>
        <span class="tuile-valeur"><?= format_fcfa($s['acomptes']) ?></span>
        <span class="tuile-detail">paiements approuvés sur la période</span>
        <span class="tuile-variation"><?= e(stats_variation($s['acomptes'], $prec['acomptes'])) ?></span>
    </div>
    <div class="tuile">
        <span class="tuile-libelle">Réservations abandonnées</span>
        <span class="tuile-valeur"><?= $s['abandonnees'] ?></span>
        <span class="tuile-detail">démarrées sur le site, jamais payées</span>
        <span class="tuile-variation"><?= e(stats_variation($s['abandonnees'], $prec['abandonnees'])) ?></span>
    </div>
</section>

<?php if ($s['nb_valides'] === 0 && $s['nb_annulees'] === 0 && $s['nb_absentes'] === 0): ?>
    <p class="vide">Aucun rendez-vous sur cette période. Choisis une autre période ci-dessus.</p>
<?php endif; ?>

<section class="bloc-compte stat-bloc">
    <h2>Chiffre d'affaires <?= e($libelleSerie) ?></h2>
    <p class="discret petit-note">Rendez-vous confirmés et terminés, à la date du soin. Survole une barre pour le détail.</p>
    <?= stats_histogramme($pointsCA, 'Chiffre d\'affaires ' . $libelleSerie . ' du ' . $libellePeriode, true, 240) ?>
    <details class="stat-tableau"><summary>Voir en tableau</summary><?= stats_tableau($pointsCA, 'Chiffre d\'affaires') ?></details>
</section>

<div class="stat-grille-2">
    <section class="bloc-compte stat-bloc">
        <h2>Soins les plus demandés</h2>
        <?= stats_classement($classementSoins) ?>
    </section>
    <section class="bloc-compte stat-bloc">
        <h2>Chiffre d'affaires par praticienne</h2>
        <?= stats_classement($parPraticienne) ?>
    </section>
</div>

<div class="stat-grille-2">
    <section class="bloc-compte stat-bloc">
        <h2>Jours les plus chargés</h2>
        <p class="discret petit-note">Nombre de rendez-vous selon le jour de la semaine.</p>
        <?= stats_histogramme($pointsJours, 'Rendez-vous par jour de la semaine', false, 180) ?>
        <details class="stat-tableau"><summary>Voir en tableau</summary><?= stats_tableau($pointsJours, 'Rendez-vous') ?></details>
    </section>
    <section class="bloc-compte stat-bloc">
        <h2>Heures les plus chargées</h2>
        <p class="discret petit-note">Nombre de rendez-vous selon l'heure de début.</p>
        <?php if ($pointsHeures): ?>
            <?= stats_histogramme($pointsHeures, 'Rendez-vous par heure de début', false, 180) ?>
            <details class="stat-tableau"><summary>Voir en tableau</summary><?= stats_tableau($pointsHeures, 'Rendez-vous') ?></details>
        <?php else: ?>
            <p class="vide">Pas encore de données sur cette période.</p>
        <?php endif; ?>
    </section>
</div>

<section class="bloc-compte stat-bloc">
    <h2>D'où viennent les rendez-vous ?</h2>
    <?= stats_classement($parSource) ?>
</section>

<div class="stat-infobulle" id="stat-infobulle" role="tooltip" hidden></div>
<script>
(function () {
    var bulle = document.getElementById('stat-infobulle');
    function montrer(el, x, y) {
        bulle.textContent = el.getAttribute('data-info');
        bulle.hidden = false;
        var l = Math.min(window.innerWidth - bulle.offsetWidth - 8, Math.max(8, x - bulle.offsetWidth / 2));
        bulle.style.left = l + window.scrollX + 'px';
        bulle.style.top = (y - bulle.offsetHeight - 12 + window.scrollY) + 'px';
    }
    document.querySelectorAll('.stat-point').forEach(function (el) {
        el.addEventListener('pointermove', function (e) { montrer(el, e.clientX, e.clientY); });
        el.addEventListener('pointerleave', function () { bulle.hidden = true; });
        el.addEventListener('focus', function () { var r = el.getBoundingClientRect(); montrer(el, r.left + r.width / 2, r.top + 20); });
        el.addEventListener('blur', function () { bulle.hidden = true; });
        el.addEventListener('keydown', function (e) { if (e.key === 'Escape') { bulle.hidden = true; } });
    });
})();
</script>

<?php require __DIR__ . '/../includes/admin_footer.php';
