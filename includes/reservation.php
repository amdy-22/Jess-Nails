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
function creneaux_disponibles(array $prestation, DateTimeImmutable $jour, bool $verrouiller = false, ?int $praticienneId = null, int $delaiMinutes = DELAI_MIN_MINUTES): array
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

    // Rendez-vous qui occupent déjà la journée (confirmés ou encore bloqués)
    $stmt = db()->prepare(
        'SELECT r.praticienne_id, r.debut, r.fin FROM reservations r
         WHERE r.debut < ? AND r.fin > ? AND ' . sql_reservation_bloquante()
    );
    $stmt->execute([$fermeture->format('Y-m-d H:i:s'), $ouverture->format('Y-m-d H:i:s')]);

    $occupe = [];
    $charge = array_fill_keys($praticiennes, 0);   // minutes déjà réservées ce jour, par praticienne
    foreach ($stmt->fetchAll() as $r) {
        $occupe[$r['praticienne_id']][] = [strtotime($r['debut']), strtotime($r['fin'])];
        if (isset($charge[$r['praticienne_id']])) {
            $charge[$r['praticienne_id']] += (strtotime($r['fin']) - strtotime($r['debut'])) / 60;
        }
    }

    $creneaux = [];
    for ($debut = $ouverture; $debut->modify("+$duree minutes") <= $fermeture; $debut = $debut->modify('+' . PAS_CRENEAU_MIN . ' minutes')) {
        if ($debut < $auPlusTot) {
            continue;
        }
        $d = $debut->getTimestamp();
        $f = $d + $duree * 60;

        // Première praticienne libre sur tout l'intervalle
                // Parmi les praticiennes libres sur tout l'intervalle, on prend la moins chargée du jour
        $choisie = null;
        foreach ($praticiennes as $pid) {
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