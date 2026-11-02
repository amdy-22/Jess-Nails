<?php
/**
 * Webhook FedaPay : FedaPay appelle cette URL (POST) à chaque changement d'état.
 * Il confirme la réservation même si le client ferme son navigateur avant le retour.
 *
 * Attention : FedaPay doit pouvoir joindre cette URL depuis Internet (HTTPS).
 * Elle ne fonctionne donc pas en local sur WAMP, seulement une fois en ligne.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/fedapay.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$payload = file_get_contents('php://input') ?: '';
$entete  = $_SERVER['HTTP_X_FEDAPAY_SIGNATURE'] ?? '';

if (PAYMENT_WEBHOOK_SECRET === '' || !fedapay_signature_valide($payload, $entete, PAYMENT_WEBHOOK_SECRET)) {
    http_response_code(400);
    exit('Signature invalide');
}

$evenement   = json_decode($payload, true);
$nom         = $evenement['name'] ?? '';
$transaction = $evenement['entity'] ?? null;

if (str_starts_with($nom, 'transaction.') && !empty($transaction['id'])) {
    try {
        appliquer_transaction_fedapay($transaction, $payload);
    } catch (Throwable $e) {
        error_log('[FedaPay webhook] ' . $e->getMessage());
        http_response_code(500);   // FedaPay réessaiera plus tard
        exit;
    }
}

http_response_code(200);
echo 'OK';