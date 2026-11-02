<?php
/**
 * Logique de réservation : calcul des créneaux libres.
 * À inclure après auth.php :
 *   require_once __DIR__ . '/../includes/reservation.php';
 */

const PAS_CRENEAU_MIN    = 15;  // un créneau toutes les 15 minutes
const DELAI_MIN_MINUTES  = 60;  // pas de réservation à moins d'1 h
const HORIZON_JOURS      = 60;  // réservation possible jusqu'à 60 jours à l'avance
const EXPIRATION_ATTENTE = 30;  // un créneau non payé est libéré après 30 min
const PAIEMENT_EN_COURS  = 20;  // ... sauf si un paiement a été lancé il y a moins de 20 min
const DELAI_ANNULATION_H = 06;  // la cliente peut annuler jusqu'à 06 h avant le rendez-vous
const RAPPEL_HEURES_AVANT = 1;  // rappel par email 1 h avant le rendez-vous

/**
 * Libellé et couleur du statut tel que la cliente le voit.
 * $bloquee : la réservation en attente bloque-t-elle encore son créneau ?
 */
function statut_affiche(array $resa, bool $bloquee): array
{
    $passe = new DateTimeImmutable($resa['debut']) < new DateTimeImmutable();
    return match (true) {
        $resa['statut'] === 'confirmee' && !$passe       => ['À venir', 'ok'],
        $resa['statut'] === 'confirmee' && $passe        => ['Passé', 'neutre'],
        $resa['statut'] === 'en_attente_paiement' && $bloquee && !$passe => ['À confirmer', 'attente'],
        $resa['statut'] === 'en_attente_paiement'        => ['Expirée (non payée)', 'neutre'],
        $resa['statut'] === 'annulee'                    => ['Annulée', 'annule'],
        $resa['statut'] === 'terminee'                   => ['Terminé', 'neutre'],
        $resa['statut'] === 'absente'                    => ['Absente', 'annule'],
        default                                          => [$resa['statut'], 'neutre'],
    };
}

/** La cliente peut-elle encore déplacer elle-même ? (même délai que l'annulation) */
function deplacement_possible(array $resa): bool
{
    $limite = (new DateTimeImmutable())->modify('+' . DELAI_ANNULATION_H . ' hours');
    return $resa['statut'] === 'confirmee' && new DateTimeImmutable($resa['debut']) > $limite;
}

/**
 * Déplace un rendez-vous confirmé vers un nouvel horaire, sans risque de double réservation.
 * Renvoie l'ancien début (pour l'email) ou null si le créneau n'est plus libre.
 */
function deplacer_reservation(int $id, DateTimeImmutable $jour, string $heure, ?int $praticienneId, int $delaiMinutes): ?DateTimeImmutable
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT r.*, p.duree_minutes FROM reservations r JOIN prestations p ON p.id = r.prestation_id
                               WHERE r.id = ? AND r.statut = 'confirmee' FOR UPDATE");
        $stmt->execute([$id]);
        $resa = $stmt->fetch();
        if (!$resa) {
            $pdo->rollBack();
            return null;
        }
        $prestation = ['id' => $resa['prestation_id'], 'duree_minutes' => $resa['duree_minutes']];
        $libres = creneaux_disponibles($prestation, $jour, true, $praticienneId, $delaiMinutes, $id);
        if (!isset($libres[$heure])) {
            $pdo->rollBack();
            return null;
        }
        $debut = new DateTimeImmutable($jour->format('Y-m-d') . ' ' . $heure);
        $fin   = $debut->modify('+' . (int) $resa['duree_minutes'] . ' minutes');
        // Nouveau créneau : le rappel repart pour le nouvel horaire
        $pdo->prepare('UPDATE reservations SET debut = ?, fin = ?, praticienne_id = ?, rappel_envoye_le = NULL WHERE id = ?')
            ->execute([$debut->format('Y-m-d H:i:s'), $fin->format('Y-m-d H:i:s'), $libres[$heure], $id]);
        $pdo->commit();
        return new DateTimeImmutable($resa['debut']);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** La cliente peut-elle encore annuler elle-même ? */
function annulation_possible(array $resa, bool $bloquee): bool
{
    $limite = (new DateTimeImmutable())->modify('+' . DELAI_ANNULATION_H . ' hours');
    $actif  = $resa['statut'] === 'confirmee' || ($resa['statut'] === 'en_attente_paiement' && $bloquee);
    return $actif && new DateTimeImmutable($resa['debut']) > $limite;
}

/**
 * Condition SQL : la réservation "r" bloque-t-elle encore son créneau ?
 * (confirmée, ou en attente depuis peu, ou paiement Mobile Money en cours)
 */
function sql_reservation_bloquante(): string
{
    return "(r.statut = 'confirmee'
             OR (r.statut = 'en_attente_paiement'
                 AND (r.created_at > NOW() - INTERVAL " . EXPIRATION_ATTENTE . " MINUTE
                      OR EXISTS (SELECT 1 FROM paiements pa
                                 WHERE pa.reservation_id = r.id AND pa.statut = 'en_attente'
                                   AND pa.created_at > NOW() - INTERVAL " . PAIEMENT_EN_COURS . " MINUTE))))";
}

/** La réservation (en attente) bloque-t-elle encore son créneau ? */
function reservation_encore_bloquee(int $reservationId): bool
{
    $stmt = db()->prepare('SELECT 1 FROM reservations r WHERE r.id = ? AND ' . sql_reservation_bloquante());
    $stmt->execute([$reservationId]);
    return (bool) $stmt->fetchColumn();
}

/** Prestation active par son id, avec sa catégorie. */
function prestation_active(int $id): ?array
{
    $stmt = db()->prepare(
        'SELECT p.*, c.nom AS categorie
         FROM prestations p JOIN categories c ON c.id = p.categorie_id
         WHERE p.id = ? AND p.actif = 1'
    );
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/**
 * Convertit "2026-11-15" en date, si elle est dans la fenêtre autorisée.
 * Renvoie null si le format ou la date ne conviennent pas.
 */
function jour_reservable(string $date): ?DateTimeImmutable
{
    $jour = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$jour || $jour->format('Y-m-d') !== $date) {
        return null;
    }
    $aujourdhui = new DateTimeImmutable('today');
    $max        = $aujourdhui->modify('+' . HORIZON_JOURS . ' days');
    return ($jour >= $aujourdhui && $jour <= $max) ? $jour : null;
}

/** Motif de fermeture exceptionnelle ce jour-là, ou null si ouvert. */
function fermeture_du_jour(DateTimeImmutable $jour): ?string
{
    $stmt = db()->prepare("SELECT COALESCE(motif, 'Fermeture exceptionnelle') FROM fermetures WHERE date = ?");
    $stmt->execute([$jour->format('Y-m-d')]);
    $motif = $stmt->fetchColumn();
    return $motif === false ? null : $motif;
}

/**
 * Créneaux libres pour une prestation un jour donné.
 * Renvoie ['09:00' => id_praticienne, '09:15' => id_praticienne, ...]
 *
 * $verrouiller = true : à utiliser DANS une transaction au moment d'enregistrer,
 * pour que deux clientes ne prennent pas la même place en même temps.
 */
function creneaux_disponibles(array $prestation, DateTimeImmutable $jour, bool $verrouiller = false, ?int $praticienneId = null, int $delaiMinutes = DELAI_MIN_MINUTES, ?int $ignorerReservationId = null): array
{
    if (fermeture_du_jour($jour) !== null) {
        return [];
    }

    // Horaires du jour (1 = lundi ... 7 = dimanche)
    $stmt = db()->prepare('SELECT heure_ouverture, heure_fermeture FROM horaires WHERE jour_semaine = ?');
    $stmt->execute([(int) $jour->format('N')]);
    $h = $stmt->fetch();
    if (!$h || $h['heure_ouverture'] === null || $h['heure_fermeture'] === null) {
        return [];
    }

    $date      = $jour->format('Y-m-d');
    $ouverture = new DateTimeImmutable($date . ' ' . $h['heure_ouverture']);
    $fermeture = new DateTimeImmutable($date . ' ' . $h['heure_fermeture']);
    $duree     = (int) $prestation['duree_minutes'];
    $auPlusTot = (new DateTimeImmutable())->modify(($delaiMinutes >= 0 ? '+' : '') . $delaiMinutes . ' minutes');

    // Praticiennes actives (verrouillées pendant l'enregistrement)
    $sql = 'SELECT id FROM praticiennes WHERE actif = 1 ORDER BY id' . ($verrouiller ? ' FOR UPDATE' : '');
    $praticiennes = db()->query($sql)->fetchAll(PDO::FETCH_COLUMN);
    if ($praticienneId !== null) {
        // La cliente a choisi une praticienne : on ne regarde que son planning
        $praticiennes = in_array($praticienneId, array_map('intval', $praticiennes), true) ? [$praticienneId] : [];
    }
    // Planning de chacune ce jour-là : on retire celles en congé ou en repos
    $plannings    = planning_du_jour($praticiennes, $jour, $ouverture, $fermeture);
    $praticiennes = array_keys($plannings);
    if (!$praticiennes) {
        return [];
    }

    // Rendez-vous qui occupent déjà la journée (confirmés ou encore bloqués)
    $stmt = db()->prepare(
        'SELECT r.praticienne_id, r.debut, r.fin FROM reservations r
         WHERE r.debut < ? AND r.fin > ? AND r.id <> ? AND ' . sql_reservation_bloquante()
    );
    $stmt->execute([$fermeture->format('Y-m-d H:i:s'), $ouverture->format('Y-m-d H:i:s'), (int) $ignorerReservationId]);

    $occupe = [];
    $charge = array_fill_keys($praticiennes, 0);   // minutes déjà réservées ce jour, par praticienne
    foreach ($stmt->fetchAll() as $r) {
        $occupe[$r['praticienne_id']][] = [strtotime($r['debut']), strtotime($r['fin'])];
        if (isset($charge[$r['praticienne_id']])) {
            $charge[$r['praticienne_id']] += (strtotime($r['fin']) - strtotime($r['debut'])) / 60;
        }
    }
    // La pause déjeuner de chacune est traitée comme un rendez-vous (sans compter dans sa charge)
    foreach ($plannings as $pid => $pl) {
        if ($pl['pause']) {
            $occupe[$pid][] = $pl['pause'];
        }
    }

    $creneaux = [];
    for ($debut = $ouverture; $debut->modify("+$duree minutes") <= $fermeture; $debut = $debut->modify('+' . PAS_CRENEAU_MIN . ' minutes')) {
        if ($debut < $auPlusTot) {
            continue;
        }
        $d = $debut->getTimestamp();
        $f = $d + $duree * 60;

        // Parmi les praticiennes libres sur tout l'intervalle, on prend la moins chargée du jour
        $choisie = null;
        foreach ($praticiennes as $pid) {
            // Le soin doit tenir entièrement dans ses heures de travail
            if ($d < $plannings[$pid]['debut'] || $f > $plannings[$pid]['fin']) {
                continue;
            }
            foreach ($occupe[$pid] ?? [] as [$od, $of]) {
                if ($od < $f && $of > $d) {   // chevauchement
                    continue 2;
                }
            }
            if ($choisie === null || $charge[$pid] < $charge[$choisie]) {
                $choisie = $pid;
            }
        }
        if ($choisie !== null) {
            $creneaux[$debut->format('H:i')] = (int) $choisie;
        }
    }
    return $creneaux;
}

/**
 * Heures de travail de chaque praticienne un jour donné, bornées par l'ouverture de l'institut.
 * Renvoie [id => ['debut' => ts, 'fin' => ts, 'pause' => [ts, ts] ou null]]
 * Une praticienne en congé ou en repos ce jour-là n'apparaît pas.
 * Sans planning enregistré, elle suit les horaires de l'institut, sans pause.
 */
function planning_du_jour(array $ids, DateTimeImmutable $jour, DateTimeImmutable $ouverture, DateTimeImmutable $fermeture): array
{
    if (!$ids) {
        return [];
    }
    $ids   = array_map('intval', $ids);
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $date  = $jour->format('Y-m-d');

    // En congé ce jour-là
    $stmt = db()->prepare("SELECT DISTINCT praticienne_id FROM praticienne_absences
                           WHERE praticienne_id IN ($marks) AND ? BETWEEN date_debut AND date_fin");
    $stmt->execute([...$ids, $date]);
    $absentes = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    // Planning de la semaine type pour ce jour
    $stmt = db()->prepare("SELECT * FROM praticienne_horaires WHERE praticienne_id IN ($marks) AND jour_semaine = ?");
    $stmt->execute([...$ids, (int) $jour->format('N')]);
    $lignes = [];
    foreach ($stmt->fetchAll() as $l) {
        $lignes[(int) $l['praticienne_id']] = $l;
    }
    // Une praticienne qui a un planning enregistré, mais rien pour ce jour, ne travaille pas ce jour
    $stmt = db()->prepare("SELECT DISTINCT praticienne_id FROM praticienne_horaires WHERE praticienne_id IN ($marks)");
    $stmt->execute($ids);
    $avecPlanning = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    $ts = fn(?string $heure) => $heure === null ? null : strtotime($date . ' ' . $heure);
    $ouv = $ouverture->getTimestamp();
    $fer = $fermeture->getTimestamp();

    $resultat = [];
    foreach ($ids as $pid) {
        if (in_array($pid, $absentes, true)) {
            continue;
        }
        if (!in_array($pid, $avecPlanning, true)) {
            $resultat[$pid] = ['debut' => $ouv, 'fin' => $fer, 'pause' => null];   // horaires de l'institut
            continue;
        }
        $l = $lignes[$pid] ?? null;
        if (!$l || $l['heure_debut'] === null || $l['heure_fin'] === null) {
            continue;   // repos ce jour-là
        }
        $debut = max($ouv, $ts($l['heure_debut']));
        $fin   = min($fer, $ts($l['heure_fin']));
        if ($fin <= $debut) {
            continue;
        }
        $pause = ($l['pause_debut'] !== null && $l['pause_fin'] !== null)
            ? [$ts($l['pause_debut']), $ts($l['pause_fin'])]
            : null;
        $resultat[$pid] = ['debut' => $debut, 'fin' => $fin, 'pause' => $pause];
    }
    return $resultat;
}

/** Range les créneaux en Matin / Après-midi / Soir pour l'affichage. */
function grouper_creneaux(array $creneaux): array
{
    $groupes = ['Matin' => [], 'Après-midi' => [], 'Soir' => []];
    foreach (array_keys($creneaux) as $heure) {
        $h = (int) substr($heure, 0, 2);
        $cle = $h < 12 ? 'Matin' : ($h < 17 ? 'Après-midi' : 'Soir');
        $groupes[$cle][] = $heure;
    }
    return array_filter($groupes);
}

/** "dimanche 15 novembre 2026" sans dépendre de l'extension intl. */
function format_date_fr(DateTimeInterface $d, bool $avecHeure = false): string
{
    static $jours = [1 => 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];
    static $mois  = [1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet',
                     'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    $txt = $jours[(int) $d->format('N')] . ' ' . $d->format('j') . ' '
         . $mois[(int) $d->format('n')] . ' ' . $d->format('Y');
    return $avecHeure ? $txt . ' à ' . $d->format('H\hi') : $txt;
}

/** Mois abrégé : "janv.", "févr.", "mars"... */
function mois_court(DateTimeInterface $d): string
{
    static $mois = [1 => 'janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.',
                    'août', 'sept.', 'oct.', 'nov.', 'déc.'];
    return $mois[(int) $d->format('n')];
}

/** Total déjà payé en ligne pour une réservation (0 si rien). */
function montant_paye(int $reservationId): int
{
    $stmt = db()->prepare("SELECT COALESCE(SUM(montant_fcfa), 0) FROM paiements WHERE reservation_id = ? AND statut = 'approuve'");
    $stmt->execute([$reservationId]);
    return (int) $stmt->fetchColumn();
}