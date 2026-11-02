<?php
/**
 * Paiement Mobile Money via FedaPay (API REST, sans SDK).
 * Doc : https://docs.fedapay.com
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/notifications.php';

/** URL de base de l'API selon le mode (test ou réel). */
function fedapay_base(): string
{
    if (defined('FEDAPAY_API_BASE') && FEDAPAY_API_BASE !== '') {
        return rtrim(FEDAPAY_API_BASE, '/');           // surcharge (tests)
    }
    return PAYMENT_SANDBOX
        ? 'https://sandbox-api.fedapay.com/v1'
        : 'https://api.fedapay.com/v1';
}

function fedapay_est_configure(): bool
{
    return PAYMENT_SECRET_KEY !== '';
}

/**
 * Appel à l'API FedaPay. Renvoie la réponse JSON décodée.
 * Lève une RuntimeException en cas d'erreur réseau ou de réponse 4xx/5xx.
 */
function fedapay_request(string $methode, string $chemin, ?array $corps = null): array
{
    $ch = curl_init(fedapay_base() . $chemin);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $methode,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . PAYMENT_SECRET_KEY,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
    ]);
    if ($corps !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($corps));
    }

    $reponse = curl_exec($ch);
    $code    = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $erreur  = curl_error($ch);

    if ($reponse === false) {
        throw new RuntimeException('FedaPay injoignable : ' . $erreur);
    }
    $data = json_decode($reponse, true) ?? [];
    if ($code >= 400) {
        $msg = $data['message'] ?? mb_substr($reponse, 0, 200);
        throw new RuntimeException("FedaPay a répondu $code : $msg");
    }
    return $data;
}

/**
 * Crée la transaction FedaPay et renvoie ['id' => ..., 'url' => page de paiement].
 */
function fedapay_creer_paiement(array $resa, array $client, int $montant): array
{
    $client_complet = [
        'firstname'    => $client['prenom'],
        'lastname'     => $client['nom'],
        'email'        => $client['email'],
        'phone_number' => ['number' => $client['telephone'], 'country' => 'bj'],
    ];
    $corps = [
        'description'  => 'Acompte réservation #' . $resa['id'] . ' - ' . $resa['soin'],
        'amount'       => $montant,
        'currency'     => ['iso' => 'XOF'],
        'callback_url' => BASE_URL . '/paiement/retour.php',
        'customer'     => $client_complet,
    ];

    try {
        $data = fedapay_request('POST', '/transactions', $corps);
    } catch (RuntimeException $e) {
        // Si FedaPay refuse le format du numéro, on réessaie sans le téléphone
        // (le client le saisira sur la page de paiement).
        unset($corps['customer']['phone_number']);
        $data = fedapay_request('POST', '/transactions', $corps);
    }

    $transaction = $data['v1/transaction'] ?? null;
    if (empty($transaction['id'])) {
        throw new RuntimeException('Réponse FedaPay inattendue à la création.');
    }

    $jeton = fedapay_request('POST', '/transactions/' . $transaction['id'] . '/token');
    if (empty($jeton['url'])) {
        throw new RuntimeException('FedaPay n\'a pas renvoyé de lien de paiement.');
    }

    return ['id' => (string) $transaction['id'], 'url' => $jeton['url']];
}

/** Lit l'état réel d'une transaction chez FedaPay. */
function fedapay_transaction(string $id): array
{
    $data = fedapay_request('GET', '/transactions/' . rawurlencode($id));
    $transaction = $data['v1/transaction'] ?? null;
    if (!$transaction) {
        throw new RuntimeException('Transaction FedaPay introuvable.');
    }
    return $transaction;
}

/**
 * Vérifie l'en-tête X-FEDAPAY-SIGNATURE ("t=TIMESTAMP,s=SIGNATURE").
 * Signature = HMAC-SHA256("TIMESTAMP.PAYLOAD", secret du webhook).
 */
function fedapay_signature_valide(string $payload, string $entete, string $secret, int $tolerance = 300): bool
{
    $timestamp  = null;
    $signatures = [];
    foreach (explode(',', $entete) as $element) {
        [$cle, $valeur] = array_pad(explode('=', trim($element), 2), 2, '');
        if ($cle === 't' && ctype_digit($valeur)) {
            $timestamp = (int) $valeur;
        } elseif ($cle === 's') {
            $signatures[] = $valeur;
        }
    }
    if ($timestamp === null || !$signatures) {
        return false;
    }
    if (abs(time() - $timestamp) > $tolerance) {   // protège contre le rejeu
        return false;
    }

    $attendue = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
    foreach ($signatures as $sig) {
        if (hash_equals($attendue, $sig)) {
            return true;
        }
    }
    return false;
}

/** Statut FedaPay -> statut de notre table paiements (null = rien à changer). */
function fedapay_statut_local(string $statut): ?string
{
    return match ($statut) {
        'approved', 'transferred'      => 'approuve',
        'declined', 'canceled'         => 'refuse',
        'refunded'                     => 'rembourse',
        default                        => null,   // pending, etc.
    };
}

/**
 * Applique l'état d'une transaction FedaPay à notre base.
 * Appelé par retour.php ET par webhook.php : doit pouvoir être rejoué sans effet de bord.
 * Renvoie le statut local du paiement, ou null si le paiement est inconnu.
 */
function appliquer_transaction_fedapay(array $transaction, string $brut = ''): ?string
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            "SELECT * FROM paiements WHERE fournisseur = 'fedapay' AND transaction_ref = ? FOR UPDATE"
        );
        $stmt->execute([(string) $transaction['id']]);
        $paiement = $stmt->fetch();

        if (!$paiement) {
            $pdo->rollBack();
            return null;
        }

        $nouveau = fedapay_statut_local((string) ($transaction['status'] ?? ''));

        // Sécurité : le montant payé doit correspondre au montant attendu
        if ($nouveau === 'approuve' && (int) ($transaction['amount'] ?? 0) !== (int) $paiement['montant_fcfa']) {
            $nouveau = 'refuse';
        }

        // Rien de neuf, ou paiement déjà approuvé (on ne revient jamais en arrière, sauf remboursement)
        if ($nouveau === null || $nouveau === $paiement['statut']
            || ($paiement['statut'] === 'approuve' && $nouveau !== 'rembourse')) {
            $pdo->commit();
            return $paiement['statut'];
        }

        $pdo->prepare('UPDATE paiements SET statut = ?, reponse_brute = ? WHERE id = ?')
            ->execute([$nouveau, $brut !== '' ? $brut : json_encode($transaction), $paiement['id']]);

        $vientDEtreConfirmee = false;
        $acompteApresCoup    = false;
        if ($nouveau === 'approuve') {
            $maj = $pdo->prepare(
                "UPDATE reservations SET statut = 'confirmee'
                 WHERE id = ? AND statut = 'en_attente_paiement'"   // une résa annulée par la cliente reste annulée
            );
            $maj->execute([$paiement['reservation_id']]);
            $vientDEtreConfirmee = $maj->rowCount() > 0;

            // Résa déjà confirmée "sur place" : c'est un acompte payé après coup
            if (!$vientDEtreConfirmee) {
                $st = $pdo->prepare('SELECT statut FROM reservations WHERE id = ?');
                $st->execute([$paiement['reservation_id']]);
                $acompteApresCoup = $st->fetchColumn() === 'confirmee';
            }
        }

        $pdo->commit();

        // Emails client + admin : une seule fois, au moment de la confirmation.
        // Une erreur d'email ne doit jamais annuler un paiement validé.
        try {
            if ($vientDEtreConfirmee) {
                notifier_reservation_confirmee((int) $paiement['reservation_id']);
            } elseif ($acompteApresCoup) {
                notifier_acompte_recu((int) $paiement['reservation_id']);
            }
        } catch (Throwable $e) {
            error_log('[Emails] ' . $e->getMessage());
        }

        return $nouveau;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}