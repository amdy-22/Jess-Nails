<?php
/**
 * Avis des clientes (étape 25).
 * Une cliente peut noter un rendez-vous TERMINÉ (une fois). L'avis reste en attente jusqu'à ce que l'admin
 * le publie. Toutes les fonctions de lecture sont tolérantes : table absente = pas d'avis, le site continue.
 */
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/reservation.php';

const AVIS_COMMENTAIRE_MAX = 600;

/** Étoiles en texte, lisibles par un lecteur d'écran : « 4 sur 5 ». */
function etoiles_html(int $note): string
{
    $note = max(0, min(5, $note));
    return '<span class="etoiles" role="img" aria-label="' . $note . ' sur 5">'
        . str_repeat('★', $note) . '<span class="etoiles-vides">' . str_repeat('★', 5 - $note) . '</span></span>';
}

/** « Awa Dossou » -> « Awa D. » (on n'affiche jamais le nom complet). */
function nom_public(string $prenom, string $nom): string
{
    $initiale = $nom !== '' ? ' ' . mb_strtoupper(mb_substr($nom, 0, 1)) . '.' : '';
    return trim($prenom) . $initiale;
}

/** Avis publiés, les plus récents d'abord. */
function avis_publies(int $limite = 6): array
{
    try {
        return db()->query(
            "SELECT a.note, a.commentaire, a.created_at, u.prenom, u.nom, COALESCE(r.libelle, p.nom) AS soin
             FROM avis a
             JOIN reservations r ON r.id = a.reservation_id
             JOIN prestations p  ON p.id = r.prestation_id
             JOIN utilisateurs u ON u.id = a.client_id
             WHERE a.statut = 'publie'
             ORDER BY a.created_at DESC LIMIT " . (int) $limite
        )->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

/** Moyenne et nombre des avis publiés : ['moyenne' => 4.7, 'nombre' => 12], ou nombre 0. */
function avis_resume(): array
{
    try {
        $r = db()->query("SELECT COUNT(*) AS n, AVG(note) AS m FROM avis WHERE statut = 'publie'")->fetch();
        return ['moyenne' => $r['m'] !== null ? round((float) $r['m'], 1) : 0.0, 'nombre' => (int) $r['n']];
    } catch (PDOException $e) {
        return ['moyenne' => 0.0, 'nombre' => 0];
    }
}

function avis_en_attente_nombre(): int
{
    try {
        return (int) db()->query("SELECT COUNT(*) FROM avis WHERE statut = 'en_attente'")->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
}

/** Avis déjà laissés par une cliente : [id de réservation => statut]. */
function avis_de_la_cliente(int $clientId): array
{
    try {
        $stmt = db()->prepare('SELECT reservation_id, statut FROM avis WHERE client_id = ?');
        $stmt->execute([$clientId]);
        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Quand l'admin marque un rendez-vous « terminé » : e-mail de demande d'avis (une seule fois par rendez-vous).
 * Renvoie une phrase pour le message de confirmation de l'admin, ou '' si rien n'est parti.
 */
function demander_avis(int $reservationId): string
{
    try {
        $stmt = db()->prepare(
            "SELECT r.id, r.avis_demande_le, COALESCE(r.libelle, p.nom) AS soin, u.prenom, u.nom, u.email,
                    (SELECT COUNT(*) FROM avis a WHERE a.reservation_id = r.id) AS deja
             FROM reservations r
             JOIN prestations p  ON p.id = r.prestation_id
             JOIN utilisateurs u ON u.id = r.client_id
             WHERE r.id = ? AND r.statut = 'terminee'"
        );
        $stmt->execute([$reservationId]);
        $r = $stmt->fetch();
        if (!$r || $r['avis_demande_le'] !== null || (int) $r['deja'] > 0 || !$r['email']) {
            return '';
        }

        $contenu = '<p>Bonjour ' . e($r['prenom']) . ',</p>'
            . '<p>Merci d\'être venue chez nous pour ton soin <strong>' . e($r['soin']) . '</strong>. '
            . 'Ton avis nous aide à nous améliorer, et il aide d\'autres clientes à choisir. Ça prend 30 secondes.</p>'
            . bouton_email('Donner mon avis', BASE_URL . '/avis?reservation=' . (int) $r['id'])
            . '<p style="color:#6e5f6c;font-size:13px;margin-top:24px;">À très vite,<br>L\'équipe ' . e(APP_NAME) . '</p>';

        $ok = envoyer_email(
            [['email' => $r['email'], 'name' => $r['prenom'] . ' ' . $r['nom']]],
            'Comment s\'est passé ton rendez-vous ?',
            gabarit_email('Ton avis nous intéresse', $contenu, 'Donne ton avis en 30 secondes')
        );
        if ($ok) {
            db()->prepare('UPDATE reservations SET avis_demande_le = NOW() WHERE id = ?')->execute([$r['id']]);
            return ' Une demande d\'avis a été envoyée à ' . $r['prenom'] . '.';
        }
    } catch (Throwable $e) {
        error_log('[Avis] ' . $e->getMessage());
    }
    return '';
}

/** Onglets Messages | Avis, communs aux deux pages d'admin. */
function onglets_messages_avis(string $actif): string
{
    try {
        $nonLus = (int) db()->query('SELECT COUNT(*) FROM messages_contact WHERE lu = 0')->fetchColumn();
    } catch (PDOException $e) {
        $nonLus = 0;
    }
    $attente = avis_en_attente_nombre();
    $lien = function (string $cle, string $url, string $libelle, int $n) use ($actif): string {
        return '<a href="' . ADMIN_URL . '/' . $url . '" class="pastille' . ($actif === $cle ? ' pastille-active' : '') . '">'
            . e($libelle) . ($n > 0 ? ' <span class="compteur-nav">' . $n . '</span>' : '') . '</a>';
    };
    return '<nav class="filtres onglets-messages" aria-label="Messages et avis">'
        . $lien('messages', 'messages', 'Messages', $nonLus)
        . $lien('avis', 'avis', 'Avis des clientes', $attente)
        . '</nav>';
}
