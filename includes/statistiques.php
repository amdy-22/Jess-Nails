<?php
/**
 * Calculs et graphiques de la page Admin > Statistiques.
 *
 * Définitions (les mêmes partout sur la page) :
 *  - rendez-vous « valides » : statut confirmée ou terminée (ceux qui rapportent de l'argent) ;
 *  - chiffre d'affaires : somme des prix des rendez-vous valides, dont la part « réalisée » (terminée) ;
 *  - une réservation « en attente de paiement » n'est jamais comptée comme rendez-vous : si le
 *    créneau n'est plus bloqué, elle est comptée comme panier abandonné ;
 *  - la date d'un rendez-vous est celle du soin (debut), pas celle de la réservation.
 */

const STATS_MAX_JOURS = 1096;   // 3 ans maximum pour une période personnalisée

// =====================================================
//  Période
// =====================================================

/** Lit ?periode= (ou ?du=&au=) et renvoie la période choisie et la période précédente. */
function stats_periode(array $get): array
{
    $aujourdhui = new DateTimeImmutable('today');
    $cle = (string) ($get['periode'] ?? 'mois');
    $choix = [
        '7j'             => 'Les 7 derniers jours',
        '30j'            => 'Les 30 derniers jours',
        'mois'           => 'Ce mois-ci',
        'mois-precedent' => 'Le mois dernier',
        '90j'            => 'Les 90 derniers jours',
        'annee'          => 'Cette année',
        'perso'          => 'Dates personnalisées',
    ];
    if (!isset($choix[$cle])) {
        $cle = 'mois';
    }

    $debut = $fin = $debutPrec = $finPrec = null;   // $fin est exclue
    switch ($cle) {
        case '7j':
        case '30j':
        case '90j':
            $n = (int) $cle;
            $debut = $aujourdhui->modify('-' . ($n - 1) . ' days');
            $fin   = $aujourdhui->modify('+1 day');
            break;
        case 'mois':
            $debut = $aujourdhui->modify('first day of this month');
            $fin   = $debut->modify('+1 month');
            $debutPrec = $debut->modify('-1 month');
            $finPrec   = $debut;
            break;
        case 'mois-precedent':
            $fin   = $aujourdhui->modify('first day of this month');
            $debut = $fin->modify('-1 month');
            $debutPrec = $debut->modify('-1 month');
            $finPrec   = $debut;
            break;
        case 'annee':
            $debut = new DateTimeImmutable($aujourdhui->format('Y') . '-01-01');
            $fin   = $debut->modify('+1 year');
            $debutPrec = $debut->modify('-1 year');
            $finPrec   = $debut;
            break;
        case 'perso':
            $du = DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($get['du'] ?? ''));
            $au = DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($get['au'] ?? ''));
            if ($du && $au && $au >= $du && $du->diff($au)->days < STATS_MAX_JOURS) {
                $debut = $du;
                $fin   = $au->modify('+1 day');
            } else {
                $cle   = 'mois';   // dates invalides : on revient au mois en cours
                $debut = $aujourdhui->modify('first day of this month');
                $fin   = $debut->modify('+1 month');
                $debutPrec = $debut->modify('-1 month');
                $finPrec   = $debut;
            }
            break;
    }
    if ($debutPrec === null) {   // période « glissante » : la précédente a la même durée, juste avant
        $jours = $debut->diff($fin)->days;
        $debutPrec = $debut->modify('-' . $jours . ' days');
        $finPrec   = $debut;
    }

    return [
        'cle' => $cle, 'choix' => $choix, 'libelle' => $choix[$cle],
        'debut' => $debut, 'fin' => $fin, 'debut_prec' => $debutPrec, 'fin_prec' => $finPrec,
        'jours' => $debut->diff($fin)->days,
    ];
}

function stats_libelle_periode(DateTimeImmutable $debut, DateTimeImmutable $fin): string
{
    $dernier = $fin->modify('-1 day');
    $court = fn (DateTimeImmutable $d, bool $annee) => $d->format('j') . ' ' . mois_court($d) . ($annee ? ' ' . $d->format('Y') : '');
    if ($debut->format('Y-m-d') === $dernier->format('Y-m-d')) {
        return $court($debut, true);
    }
    return $court($debut, $debut->format('Y') !== $dernier->format('Y')) . ' au ' . $court($dernier, true);
}

// =====================================================
//  Calculs
// =====================================================

const STATS_VALIDES = "('confirmee','terminee')";

/** Tous les chiffres d'une période. */
function stats_calculer(DateTimeImmutable $debut, DateTimeImmutable $fin): array
{
    $d = $debut->format('Y-m-d H:i:s');
    $f = $fin->format('Y-m-d H:i:s');
    $pdo = db();

    $stmt = $pdo->prepare(
        "SELECT id, client_id, praticienne_id, debut, prix_fcfa, statut, source
         FROM reservations
         WHERE debut >= ? AND debut < ? AND statut <> 'en_attente_paiement'"
    );
    $stmt->execute([$d, $f]);
    $lignes = $stmt->fetchAll();

    $s = [
        'ca' => 0, 'ca_realise' => 0, 'nb_valides' => 0, 'nb_terminees' => 0, 'nb_absentes' => 0, 'nb_annulees' => 0,
        'par_jour' => [], 'par_semaine_jour' => array_fill(1, 7, 0), 'par_heure' => [], 'par_source' => [], 'par_praticienne' => [],
        'clientes' => [],
    ];
    foreach ($lignes as $r) {
        switch ($r['statut']) {
            case 'annulee': $s['nb_annulees']++; continue 2;
            case 'absente': $s['nb_absentes']++; continue 2;
        }
        // confirmée ou terminée
        $prix = (int) $r['prix_fcfa'];
        $s['nb_valides']++;
        $s['ca'] += $prix;
        if ($r['statut'] === 'terminee') {
            $s['nb_terminees']++;
            $s['ca_realise'] += $prix;
        }
        $quand = new DateTimeImmutable($r['debut']);
        $jour = $quand->format('Y-m-d');
        $s['par_jour'][$jour] = ($s['par_jour'][$jour] ?? ['ca' => 0, 'nb' => 0]);
        $s['par_jour'][$jour]['ca'] += $prix;
        $s['par_jour'][$jour]['nb']++;
        $s['par_semaine_jour'][(int) $quand->format('N')]++;
        $h = (int) $quand->format('G');
        $s['par_heure'][$h] = ($s['par_heure'][$h] ?? 0) + 1;
        $s['par_source'][$r['source']] = ($s['par_source'][$r['source']] ?? 0) + 1;
        $p = (int) $r['praticienne_id'];
        $s['par_praticienne'][$p] = ($s['par_praticienne'][$p] ?? ['nb' => 0, 'ca' => 0]);
        $s['par_praticienne'][$p]['nb']++;
        $s['par_praticienne'][$p]['ca'] += $prix;
        $s['clientes'][(int) $r['client_id']] = true;
    }

    $s['panier_moyen']      = $s['nb_valides'] > 0 ? (int) round($s['ca'] / $s['nb_valides']) : null;
    $total                  = $s['nb_valides'] + $s['nb_absentes'] + $s['nb_annulees'];
    $s['taux_annulation']   = $total > 0 ? $s['nb_annulees'] / $total * 100 : null;
    $honores                = $s['nb_terminees'] + $s['nb_absentes'];
    $s['taux_absence']      = $honores > 0 ? $s['nb_absentes'] / $honores * 100 : null;
    $s['nb_clientes']       = count($s['clientes']);

    // Parmi ces clientes, combien étaient déjà venues (ou réservées) avant le début de la période ?
    $s['nb_deja_venues'] = 0;
    if ($s['clientes']) {
        $ids = array_keys($s['clientes']);
        $marques = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare(
            "SELECT COUNT(DISTINCT client_id) FROM reservations
             WHERE client_id IN ($marques) AND debut < ? AND statut IN " . STATS_VALIDES
        );
        $stmt->execute([...$ids, $d]);
        $s['nb_deja_venues'] = (int) $stmt->fetchColumn();
    }
    $s['part_fidelite'] = $s['nb_clientes'] > 0 ? $s['nb_deja_venues'] / $s['nb_clientes'] * 100 : null;

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM utilisateurs WHERE role = 'client' AND created_at >= ? AND created_at < ?");
    $stmt->execute([$d, $f]);
    $s['nouvelles_clientes'] = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(montant_fcfa), 0) FROM paiements WHERE statut = 'approuve' AND created_at >= ? AND created_at < ?");
    $stmt->execute([$d, $f]);
    $s['acomptes'] = (int) $stmt->fetchColumn();

    // Paniers abandonnés : réservation jamais payée, dont le créneau est de nouveau libre
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM reservations r
         WHERE r.statut = 'en_attente_paiement' AND r.created_at >= ? AND r.created_at < ? AND NOT " . sql_reservation_bloquante()
    );
    $stmt->execute([$d, $f]);
    $s['abandonnees'] = (int) $stmt->fetchColumn();

    return $s;
}

/** Soins les plus demandés (détail des soins de chaque rendez-vous valide). */
function stats_top_soins(DateTimeImmutable $debut, DateTimeImmutable $fin, int $limite = 8): array
{
    $stmt = db()->prepare(
        "SELECT rp.nom, COUNT(*) AS nb, SUM(rp.prix_fcfa) AS ca
         FROM reservation_prestations rp
         JOIN reservations r ON r.id = rp.reservation_id
         WHERE r.debut >= ? AND r.debut < ? AND r.statut IN " . STATS_VALIDES . "
         GROUP BY rp.prestation_id, rp.nom
         ORDER BY nb DESC, ca DESC, rp.nom
         LIMIT " . (int) $limite
    );
    $stmt->execute([$debut->format('Y-m-d H:i:s'), $fin->format('Y-m-d H:i:s')]);
    return $stmt->fetchAll();
}

/**
 * Découpe la période en barres : par jour, par semaine (lundi) ou par mois selon la durée.
 * Renvoie [mode, [ ['cle','etiquette','titre','ca','nb'], ... ]] avec les périodes vides à zéro.
 */
function stats_serie(array $parJour, DateTimeImmutable $debut, DateTimeImmutable $fin): array
{
    $jours = $debut->diff($fin)->days;
    $mode = $jours <= 45 ? 'jour' : ($jours <= 180 ? 'semaine' : 'mois');

    $cle = function (DateTimeImmutable $j) use ($mode): string {
        return match ($mode) {
            'jour'    => $j->format('Y-m-d'),
            'semaine' => $j->modify('monday this week')->format('Y-m-d'),
            default   => $j->format('Y-m'),
        };
    };
    $serie = [];
    for ($j = $debut; $j < $fin; $j = $j->modify('+1 day')) {
        $k = $cle($j);
        if (!isset($serie[$k])) {
            $ref = $mode === 'mois' ? new DateTimeImmutable($k . '-01') : ($mode === 'semaine' ? new DateTimeImmutable($k) : $j);
            $serie[$k] = ['cle' => $k, 'ref' => $ref, 'ca' => 0, 'nb' => 0];
        }
        if (isset($parJour[$j->format('Y-m-d')])) {
            $serie[$k]['ca'] += $parJour[$j->format('Y-m-d')]['ca'];
            $serie[$k]['nb'] += $parJour[$j->format('Y-m-d')]['nb'];
        }
    }
    foreach ($serie as &$p) {
        $r = $p['ref'];
        $p['etiquette'] = match ($mode) {
            'jour'    => $r->format('j'),
            'semaine' => $r->format('j') . ' ' . mois_court($r),
            default   => mois_court($r),
        };
        $p['titre'] = match ($mode) {
            'jour'    => ucfirst(format_date_fr($r)),
            'semaine' => 'Semaine du ' . $r->format('j') . ' ' . mois_court($r),
            default   => ucfirst(mois_court($r)) . ' ' . $r->format('Y'),
        };
    }
    unset($p);
    return [$mode, array_values($serie)];
}

// =====================================================
//  Présentation
// =====================================================

/** Variation par rapport à la période précédente, en texte (jamais seulement une couleur). */
function stats_variation(float|int|null $actuel, float|int|null $avant, bool $enPoints = false): string
{
    if ($actuel === null || $avant === null) {
        return '–';
    }
    if ($enPoints) {
        $ecart = round($actuel - $avant, 1);
        if ($ecart == 0) { return '= stable'; }
        return ($ecart > 0 ? '▲ +' : '▼ −') . str_replace('.', ',', (string) abs($ecart)) . ' pt';
    }
    if ($avant == 0) {
        return $actuel == 0 ? '= stable' : 'nouveau';
    }
    $pct = round(($actuel - $avant) / $avant * 100);
    if ($pct == 0) { return '= stable'; }
    return ($pct > 0 ? '▲ +' : '▼ −') . abs($pct) . ' %';
}

function stats_pct(?float $v): string
{
    return $v === null ? '–' : str_replace('.', ',', (string) round($v, 1)) . ' %';
}

/** Valeur courte pour les axes : 12 k, 1,2 M. */
function stats_court(float $v): string
{
    if ($v >= 1000000) { return rtrim(rtrim(number_format($v / 1000000, 1, ',', ''), '0'), ',') . ' M'; }
    if ($v >= 1000)    { return rtrim(rtrim(number_format($v / 1000, 1, ',', ''), '0'), ',') . ' k'; }
    return (string) (int) $v;
}

/** Plus petite graduation « ronde » (1, 2, 5 × 10^n) qui couvre le maximum avec 4 intervalles. */
function stats_echelle(float $max): array
{
    if ($max <= 0) { return [4, 1]; }
    $brut = $max / 4;
    $puissance = 10 ** floor(log10($brut));
    foreach ([1, 2, 2.5, 5, 10] as $m) {
        if ($m * $puissance >= $brut) { $pas = $m * $puissance; break; }
    }
    return [max(1, (int) ceil($max / $pas - 1e-9)), $pas];
}

/**
 * Histogramme en HTML/CSS (texte à taille fixe, lisible du téléphone à l'écran large, sans bibliothèque).
 * $points : [ ['etiquette' => 'lun.', 'valeur' => 12, 'titre' => 'Lundi', 'detail' => '12 rendez-vous'], ... ]
 * La barre la plus haute est en couleur pleine, les autres en rose clair.
 */
function stats_histogramme(array $points, string $description, bool $fcfa = false, int $hauteur = 200): string
{
    $n = count($points);
    if ($n === 0) { return ''; }
    $max = max(array_column($points, 'valeur'));
    [$nbPas, $pas] = stats_echelle((float) $max);
    $plafond = $nbPas * $pas;
    $idxMax = $max > 0 ? array_search($max, array_column($points, 'valeur'), true) : -1;
    $pasBureau = max(1, (int) ceil($n / 12));   // une étiquette sur N : jamais de chevauchement
    $pasMobile = $n <= 9 ? 1 : (int) ceil($n / 6);

    $html = '<div class="stat-histo" role="group" aria-label="' . e($description) . '" style="--haut:' . $hauteur . 'px">';
    $html .= '<div class="stat-plot">';
    for ($i = 0; $i <= $nbPas; $i++) {
        $bas = round($i / $nbPas * 100, 2);
        $html .= '<div class="stat-ligne' . ($i === 0 ? ' base' : '') . '" style="bottom:' . $bas . '%"><span>' . e(stats_court($i * $pas)) . '</span></div>';
    }
    $html .= '<div class="stat-cols">';
    foreach ($points as $i => $p) {
        $info = ($p['titre'] ?? $p['etiquette']) . ' : ' . ($p['detail'] ?? ($fcfa ? format_fcfa((int) $p['valeur']) : (string) $p['valeur']));
        $h = $plafond > 0 ? round($p['valeur'] / $plafond * 100, 2) : 0;
        $html .= '<div class="stat-point" data-info="' . e($info) . '" tabindex="0" role="img" aria-label="' . e($info) . '">'
              . ($p['valeur'] > 0 ? '<span class="stat-barre' . ($i === $idxMax ? ' fort' : '') . '" style="height:' . $h . '%"></span>' : '')
              . '</div>';
    }
    $html .= '</div></div><div class="stat-x">';
    foreach ($points as $i => $p) {
        $classes = ($i % $pasBureau === 0 ? ' b' : '') . ($i % $pasMobile === 0 ? ' m' : '');
        $html .= '<div class="stat-etiq' . $classes . '"><span>' . e($p['etiquette']) . '</span></div>';
    }
    return $html . '</div></div>';
}

/** Classement en barres horizontales (HTML) : un libellé, une barre, la valeur écrite. */
function stats_classement(array $lignes, string $unite = ''): string
{
    if (!$lignes) { return '<p class="vide">Pas encore de données sur cette période.</p>'; }
    $max = max(array_column($lignes, 'valeur')) ?: 1;
    $html = '<ol class="stat-classement">';
    foreach ($lignes as $l) {
        $part = max(2, round($l['valeur'] / $max * 100, 1));
        $html .= '<li><span class="stat-nom">' . e($l['nom']) . '</span>'
              . '<span class="stat-piste"><span class="stat-remplissage" style="width:' . $part . '%"></span></span>'
              . '<span class="stat-valeur">' . e($l['texte']) . '</span></li>';
    }
    return $html . '</ol>';
}

// =====================================================
//  Export CSV
// =====================================================

/** Évite qu'Excel prenne un texte pour une formule (=, +, -, @). */
function stats_cellule_csv(mixed $v): string
{
    $t = (string) $v;
    return $t !== '' && strpos("=+-@\t\r", $t[0]) !== false ? "'" . $t : $t;
}
