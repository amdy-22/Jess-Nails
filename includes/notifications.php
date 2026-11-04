<?php
/**
 * Emails envoyés quand une réservation est confirmée (paiement validé).
 */
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/reservation.php';   // format_date_fr()

function notifier_reservation_confirmee(int $reservationId, bool $prevenirAdmin = true): void
{
    $stmt = db()->prepare(
        "SELECT r.*, COALESCE(r.libelle, p.nom) AS soin, TIMESTAMPDIFF(MINUTE, r.debut, r.fin) AS duree_minutes, pr.nom AS praticienne,
                u.prenom, u.nom AS nom_client, u.email, u.telephone,
                (SELECT pa.transaction_ref FROM paiements pa
                 WHERE pa.reservation_id = r.id AND pa.statut = 'approuve'
                 ORDER BY pa.id DESC LIMIT 1) AS transaction_ref,
                (SELECT pa.montant_fcfa FROM paiements pa
                 WHERE pa.reservation_id = r.id AND pa.statut = 'approuve'
                 ORDER BY pa.id DESC LIMIT 1) AS paye
         FROM reservations r
         JOIN prestations p   ON p.id  = r.prestation_id
         JOIN praticiennes pr ON pr.id = r.praticienne_id
         JOIN utilisateurs u  ON u.id  = r.client_id
         WHERE r.id = ?"
    );
    $stmt->execute([$reservationId]);
    $r = $stmt->fetch();
    if (!$r) {
        return;
    }

    $debut   = new DateTimeImmutable($r['debut']);
    $fin     = new DateTimeImmutable($r['fin']);
    $quand   = format_date_fr($debut) . ', de ' . $debut->format('H\hi') . ' à ' . $fin->format('H\hi');
    $paye    = (int) $r['paye'];
    $reste   = max(0, (int) $r['prix_fcfa'] - $paye);

    // ---------- Email au client ----------
    $lignesClient = [
        'Soin'          => e($r['soin']) . ' (' . format_duree((int) $r['duree_minutes']) . ')',
        'Date'          => e($quand),
        'Avec'          => e($r['praticienne']),
    ];
    if ($paye > 0) {
        $lignesClient['Acompte payé'] = format_fcfa($paye);
        $lignesClient['Reste à régler sur place'] = format_fcfa($reste);
    } else {
        $lignesClient['À régler sur place'] = format_fcfa((int) $r['prix_fcfa']);
    }
    $contenuClient =
        '<p>Bonjour ' . e($r['prenom']) . ',</p>'
        . '<p>' . ($paye > 0 ? 'Ton acompte est bien reçu : ta' : 'Ta') . ' réservation est <strong>confirmée</strong>.</p>'
        . tableau_email($lignesClient)
        . (SALON_ADRESSE !== '' ? '<p><strong>Adresse :</strong> ' . e(SALON_ADRESSE) . '</p>' : '')
        . '<p>Merci d\'arriver 5 minutes avant ton rendez-vous. Pour toute question'
        . (SALON_TELEPHONE !== '' ? ', appelle-nous au <strong>' . e(SALON_TELEPHONE) . '</strong>' : ', réponds simplement à cet email')
        . '.</p>'
        . '<p style="margin:24px 0 0;"><a href="' . e(BASE_URL . '/paiement/initier?reservation=' . (int) $r['id'])
        . '" style="background:#8c3a5b;color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:999px;display:inline-block;">Voir ma réservation</a></p>'
        . '<p style="color:#6e5f6c;font-size:13px;margin-top:24px;">À très bientôt,<br>L\'équipe ' . e(APP_NAME) . '</p>';

    envoyer_email(
        [['email' => $r['email'], 'name' => $r['prenom'] . ' ' . $r['nom_client']]],
        'Réservation confirmée : ' . $r['soin'] . ' le ' . format_date_fr($debut, true),
        gabarit_email('Ta réservation est confirmée', $contenuClient),
        ADMIN_NOTIFICATION_EMAIL !== '' ? ['email' => ADMIN_NOTIFICATION_EMAIL] : null
    );

    // ---------- Email à l'admin ----------
    if (!$prevenirAdmin) {
        return;
    }
    $admins = ADMIN_NOTIFICATION_EMAIL !== ''
        ? [['email' => ADMIN_NOTIFICATION_EMAIL]]
        : array_map(fn($a) => ['email' => $a['email'], 'name' => $a['prenom']],
            db()->query("SELECT email, prenom FROM utilisateurs WHERE role = 'admin'")->fetchAll());

    $lignesAdmin = [
        'Cliente'      => e($r['prenom'] . ' ' . $r['nom_client']),
        'Téléphone'    => e($r['telephone']),
        'Email'        => e($r['email']),
        'Soin'         => e($r['soin']) . ' (' . format_duree((int) $r['duree_minutes']) . ')',
        'Date'         => e($quand),
        'Praticienne'  => e($r['praticienne']),
        'Prix'         => format_fcfa((int) $r['prix_fcfa']),
    ];
    if ($paye > 0) {
        $lignesAdmin['Acompte payé']      = format_fcfa($paye);
        $lignesAdmin['Reste à encaisser'] = format_fcfa($reste);
        $lignesAdmin['Réf. FedaPay']      = e((string) $r['transaction_ref']);
    } else {
        $lignesAdmin['Acompte']    = 'Non payé';
        $lignesAdmin['À encaisser sur place'] = format_fcfa((int) $r['prix_fcfa']);
    }
    $contenuAdmin =
        '<p>Nouvelle réservation confirmée (n° ' . (int) $r['id'] . '), '
        . ($paye > 0 ? 'avec acompte payé en ligne.' : 'règlement prévu sur place.') . '</p>'
        . tableau_email($lignesAdmin)
        . ($r['note_client']
            ? '<p><strong>Précision de la cliente :</strong><br>' . nl2br(e($r['note_client'])) . '</p>'
            : '')
        . '<p style="margin:24px 0 0;"><a href="' . e(ADMIN_URL . '/reservations')
        . '" style="background:#8c3a5b;color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:999px;display:inline-block;">Ouvrir l\'administration</a></p>';

    envoyer_email(
        $admins,
        'Nouvelle réservation : ' . $r['soin'] . ' · ' . format_date_fr($debut, true),
        gabarit_email('Nouvelle réservation confirmée', $contenuAdmin),
        ['email' => $r['email'], 'name' => $r['prenom'] . ' ' . $r['nom_client']]   // "Répondre" écrit à la cliente
    );
}

/**
 * Annulation par la cliente : email à la cliente + à l'admin.
 * $etaitConfirmee : la réservation était confirmée (l'admin est alors prévenu).
 * Le remboursement n'est mentionné que si un acompte a été payé.
 */
function notifier_annulation(int $reservationId, bool $etaitConfirmee): void
{
    $stmt = db()->prepare(
        "SELECT r.*, COALESCE(r.libelle, p.nom) AS soin, u.prenom, u.nom AS nom_client, u.email, u.telephone,
                (SELECT pa.montant_fcfa FROM paiements pa
                 WHERE pa.reservation_id = r.id AND pa.statut = 'approuve'
                 ORDER BY pa.id DESC LIMIT 1) AS paye
         FROM reservations r
         JOIN prestations p  ON p.id = r.prestation_id
         JOIN utilisateurs u ON u.id = r.client_id
         WHERE r.id = ?"
    );
    $stmt->execute([$reservationId]);
    $r = $stmt->fetch();
    if (!$r) {
        return;
    }

    $debut = new DateTimeImmutable($r['debut']);
    $quand = format_date_fr($debut, true);
    $paye  = (int) $r['paye'];

    // Cliente
    $contenu = '<p>Bonjour ' . e($r['prenom']) . ',</p>'
        . '<p>Ta réservation <strong>' . e($r['soin']) . '</strong> du <strong>' . e($quand) . '</strong> est bien annulée.</p>'
        . ($paye > 0
            ? '<p>L\'institut a été prévenu pour le remboursement de ton acompte de ' . format_fcfa($paye) . '.</p>'
            : '')
        . '<p style="margin:24px 0 0;"><a href="' . e(BASE_URL . '/reserver')
        . '" style="background:#8c3a5b;color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:999px;display:inline-block;">Réserver un autre créneau</a></p>';
    envoyer_email(
        [['email' => $r['email'], 'name' => $r['prenom'] . ' ' . $r['nom_client']]],
        'Réservation annulée : ' . $r['soin'] . ' le ' . $quand,
        gabarit_email('Ta réservation est annulée', $contenu)
    );

    // Admin : seulement si la réservation était confirmée (une simple réservation expirée ne l'intéresse pas)
    if (!$etaitConfirmee) {
        return;
    }
    $admins = ADMIN_NOTIFICATION_EMAIL !== ''
        ? [['email' => ADMIN_NOTIFICATION_EMAIL]]
        : array_map(fn($a) => ['email' => $a['email']],
            db()->query("SELECT email FROM utilisateurs WHERE role = 'admin'")->fetchAll());

    $contenuAdmin = '<p>Une cliente a annulé une réservation confirmée (n° ' . (int) $r['id'] . ').</p>'
        . tableau_email([
            'Cliente'   => e($r['prenom'] . ' ' . $r['nom_client']),
            'Téléphone' => e($r['telephone']),
            'Soin'      => e($r['soin']),
            'Date'      => e($quand),
            'Acompte'   => $paye > 0 ? format_fcfa($paye) . ' à rembourser' : 'Non payé, rien à rembourser',
        ])
        . '<p>Le créneau est de nouveau disponible à la réservation.</p>';
    envoyer_email(
        $admins,
        'Annulation : ' . $r['soin'] . ' · ' . $quand,
        gabarit_email('Réservation annulée', $contenuAdmin),
        ['email' => $r['email']]
    );
}

/** Acompte payé après coup sur une réservation déjà confirmée (règlement sur place prévu au départ). */
function notifier_acompte_recu(int $reservationId): void
{
    $stmt = db()->prepare(
        "SELECT r.*, COALESCE(r.libelle, p.nom) AS soin, u.prenom, u.nom AS nom_client, u.email
         FROM reservations r
         JOIN prestations p  ON p.id = r.prestation_id
         JOIN utilisateurs u ON u.id = r.client_id
         WHERE r.id = ?"
    );
    $stmt->execute([$reservationId]);
    $r = $stmt->fetch();
    if (!$r) {
        return;
    }
    $quand = format_date_fr(new DateTimeImmutable($r['debut']), true);
    $paye  = montant_paye($reservationId);
    $reste = max(0, (int) $r['prix_fcfa'] - $paye);
    $lignes = ['Soin' => e($r['soin']), 'Date' => e($quand), 'Acompte payé' => format_fcfa($paye), 'Reste à régler sur place' => format_fcfa($reste)];

    envoyer_email(
        [['email' => $r['email'], 'name' => $r['prenom'] . ' ' . $r['nom_client']]],
        'Acompte reçu : ' . $r['soin'] . ' le ' . $quand,
        gabarit_email('Acompte bien reçu', '<p>Bonjour ' . e($r['prenom']) . ',</p><p>Merci, ton acompte est bien reçu.</p>' . tableau_email($lignes))
    );

    $admins = ADMIN_NOTIFICATION_EMAIL !== ''
        ? [['email' => ADMIN_NOTIFICATION_EMAIL]]
        : array_map(fn($a) => ['email' => $a['email']], db()->query("SELECT email FROM utilisateurs WHERE role = 'admin'")->fetchAll());
    envoyer_email(
        $admins,
        'Acompte reçu : ' . $r['prenom'] . ' ' . $r['nom_client'] . ' · ' . $quand,
        gabarit_email('Acompte reçu', '<p>La cliente ' . e($r['prenom'] . ' ' . $r['nom_client'])
            . ' a payé son acompte en ligne pour la réservation n° ' . (int) $r['id'] . '.</p>' . tableau_email($lignes))
    );
}

/** Annulation décidée par l'institut : email à la cliente. */
function notifier_annulation_par_institut(int $reservationId): void
{
    $stmt = db()->prepare(
        "SELECT r.*, COALESCE(r.libelle, p.nom) AS soin, u.prenom, u.nom AS nom_client, u.email
         FROM reservations r
         JOIN prestations p  ON p.id = r.prestation_id
         JOIN utilisateurs u ON u.id = r.client_id
         WHERE r.id = ?"
    );
    $stmt->execute([$reservationId]);
    $r = $stmt->fetch();
    if (!$r) {
        return;
    }
    $quand = format_date_fr(new DateTimeImmutable($r['debut']), true);
    $paye  = montant_paye($reservationId);

    $contenu = '<p>Bonjour ' . e($r['prenom']) . ',</p>'
        . '<p>Nous sommes désolés : nous devons annuler ton rendez-vous <strong>' . e($r['soin'])
        . '</strong> du <strong>' . e($quand) . '</strong>.</p>'
        . ($paye > 0 ? '<p>Ton acompte de ' . format_fcfa($paye) . ' te sera remboursé.</p>' : '')
        . '<p>N\'hésite pas à choisir un autre créneau, ou à répondre à cet email pour en parler.</p>'
        . '<p style="margin:24px 0 0;"><a href="' . e(BASE_URL . '/reserver?prestation=' . (int) $r['prestation_id'])
        . '" style="background:#8c3a5b;color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:999px;display:inline-block;">Choisir un autre créneau</a></p>';

    envoyer_email(
        [['email' => $r['email'], 'name' => $r['prenom'] . ' ' . $r['nom_client']]],
        'Rendez-vous annulé : ' . $r['soin'] . ' le ' . $quand,
        gabarit_email('Ton rendez-vous est annulé', $contenu),
        ADMIN_NOTIFICATION_EMAIL !== '' ? ['email' => ADMIN_NOTIFICATION_EMAIL] : null
    );
}


/**
 * Rappel envoyé quelques heures avant le rendez-vous (script taches/rappels.php).
 * Renvoie true si l'email est parti.
 */
function notifier_rappel(int $reservationId): bool
{
    $stmt = db()->prepare(
        "SELECT r.*, COALESCE(r.libelle, p.nom) AS soin, TIMESTAMPDIFF(MINUTE, r.debut, r.fin) AS duree_minutes, pr.nom AS praticienne, u.prenom, u.nom AS nom_client, u.email,
                (SELECT COALESCE(SUM(pa.montant_fcfa), 0) FROM paiements pa
                 WHERE pa.reservation_id = r.id AND pa.statut = 'approuve') AS paye
         FROM reservations r
         JOIN prestations p   ON p.id  = r.prestation_id
         JOIN praticiennes pr ON pr.id = r.praticienne_id
         JOIN utilisateurs u  ON u.id  = r.client_id
         WHERE r.id = ?"
    );
    $stmt->execute([$reservationId]);
    $r = $stmt->fetch();
    if (!$r || !$r['email']) {
        return false;
    }

    $debut = new DateTimeImmutable($r['debut']);
    $jourTexte = match ($debut->format('Y-m-d')) {
        date('Y-m-d')                      => 'aujourd\'hui',
        date('Y-m-d', strtotime('+1 day')) => 'demain',
        default                            => 'le ' . format_date_fr($debut),
    };
    $reste = max(0, (int) $r['prix_fcfa'] - (int) $r['paye']);

    $lignes = [
        'Soin'  => e($r['soin']) . ' (' . format_duree((int) $r['duree_minutes']) . ')',
        'Heure' => e(ucfirst($jourTexte) . ' à ' . $debut->format('H\hi')),
        'Avec'  => e($r['praticienne']),
        'À régler sur place' => format_fcfa($reste),
    ];

    $contenu = '<p>Bonjour ' . e($r['prenom']) . ',</p>'
        . '<p>Petit rappel : on t\'attend <strong>' . e($jourTexte) . ' à ' . $debut->format('H\hi') . '</strong>.</p>'
        . tableau_email($lignes)
        . (SALON_ADRESSE !== '' ? '<p><strong>Adresse :</strong> ' . e(SALON_ADRESSE) . '</p>' : '')
        . '<p>Merci d\'arriver 5 minutes avant.'
        . (SALON_TELEPHONE !== '' ? ' Un empêchement ? Préviens-nous au <strong>' . e(SALON_TELEPHONE) . '</strong>, '
                                  . 'pour que le créneau profite à une autre cliente.' : '')
        . '</p>'
        . '<p style="color:#6e5f6c;font-size:13px;margin-top:24px;">À très vite,<br>L\'équipe ' . e(APP_NAME) . '</p>';

    return envoyer_email(
        [['email' => $r['email'], 'name' => $r['prenom'] . ' ' . $r['nom_client']]],
        'Rappel : ton rendez-vous ' . $jourTexte . ' à ' . $debut->format('H\hi'),
        gabarit_email('Rappel de ton rendez-vous', $contenu),
        ADMIN_NOTIFICATION_EMAIL !== '' ? ['email' => ADMIN_NOTIFICATION_EMAIL] : null
    );
}


/**
 * Rendez-vous déplacé : email à la cliente (ancien et nouvel horaire),
 * et à l'admin quand c'est la cliente qui a déplacé.
 */
function notifier_deplacement(int $reservationId, DateTimeImmutable $ancienDebut, bool $parInstitut): void
{
    $stmt = db()->prepare(
        "SELECT r.*, COALESCE(r.libelle, p.nom) AS soin, TIMESTAMPDIFF(MINUTE, r.debut, r.fin) AS duree_minutes, pr.nom AS praticienne,
                u.prenom, u.nom AS nom_client, u.email, u.telephone
         FROM reservations r
         JOIN prestations p   ON p.id  = r.prestation_id
         JOIN praticiennes pr ON pr.id = r.praticienne_id
         JOIN utilisateurs u  ON u.id  = r.client_id
         WHERE r.id = ?"
    );
    $stmt->execute([$reservationId]);
    $r = $stmt->fetch();
    if (!$r) {
        return;
    }
    $nouveau = new DateTimeImmutable($r['debut']);
    $ancien  = format_date_fr($ancienDebut, true);
    $lignes  = [
        'Soin'             => e($r['soin']) . ' (' . format_duree((int) $r['duree_minutes']) . ')',
        'Ancien horaire'   => '<span style="text-decoration:line-through;color:#6e5f6c;">' . e($ancien) . '</span>',
        'Nouvel horaire'   => e(format_date_fr($nouveau, true)),
        'Avec'             => e($r['praticienne']),
    ];

    // Cliente
    if ($r['email']) {
        $intro = $parInstitut
            ? '<p>L\'institut a déplacé ton rendez-vous. Voici le nouvel horaire :</p>'
            : '<p>Ton rendez-vous est bien déplacé. Voici le nouvel horaire :</p>';
        envoyer_email(
            [['email' => $r['email'], 'name' => $r['prenom'] . ' ' . $r['nom_client']]],
            'Rendez-vous déplacé : ' . $r['soin'] . ' le ' . format_date_fr($nouveau, true),
            gabarit_email('Rendez-vous déplacé', '<p>Bonjour ' . e($r['prenom']) . ',</p>' . $intro . tableau_email($lignes)
                . ($parInstitut ? '<p>Si cet horaire ne te convient pas, réponds simplement à cet email.</p>' : '')),
            ADMIN_NOTIFICATION_EMAIL !== '' ? ['email' => ADMIN_NOTIFICATION_EMAIL] : null
        );
    }

    // Admin : seulement quand c'est la cliente qui a déplacé
    if ($parInstitut) {
        return;
    }
    $admins = ADMIN_NOTIFICATION_EMAIL !== ''
        ? [['email' => ADMIN_NOTIFICATION_EMAIL]]
        : array_map(fn($a) => ['email' => $a['email']], db()->query("SELECT email FROM utilisateurs WHERE role = 'admin'")->fetchAll());
    envoyer_email(
        $admins,
        'Rendez-vous déplacé : ' . $r['prenom'] . ' ' . $r['nom_client'] . ' · ' . format_date_fr($nouveau, true),
        gabarit_email('Rendez-vous déplacé par la cliente',
            '<p>' . e($r['prenom'] . ' ' . $r['nom_client']) . ' (' . e($r['telephone']) . ') a déplacé son rendez-vous.</p>' . tableau_email($lignes)),
        $r['email'] ? ['email' => $r['email']] : null
    );
}