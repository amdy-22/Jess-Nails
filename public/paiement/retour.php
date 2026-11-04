<?php
/**
 * Page où FedaPay renvoie le client après le paiement :
 *   retour.php?id=ID_TRANSACTION&status=approved
 * On ne fait JAMAIS confiance au paramètre "status" de l'URL :
 * on redemande l'état réel de la transaction à FedaPay.
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/fedapay.php';

$transactionId = preg_replace('/\D/', '', (string) ($_GET['id'] ?? ''));

$stmt = db()->prepare("SELECT reservation_id FROM paiements WHERE fournisseur = 'fedapay' AND transaction_ref = ?");
$stmt->execute([$transactionId]);
$reservationId = $stmt->fetchColumn();

if ($transactionId === '' || !$reservationId) {
    flash('erreur', 'Paiement introuvable.');
    redirect(BASE_URL . '/');
}

$urlRecap = BASE_URL . '/paiement/initier?reservation=' . (int) $reservationId;

try {
    $transaction = fedapay_transaction($transactionId);
    $statut      = appliquer_transaction_fedapay($transaction);
} catch (RuntimeException $e) {
    error_log('[FedaPay retour] ' . $e->getMessage());
    flash('info', 'Nous vérifions ton paiement. Actualise cette page dans quelques instants.');
    redirect($urlRecap);
}

match ($statut) {
    'approuve'  => flash('succes', 'Paiement reçu, ta réservation est confirmée !'),
    'refuse'    => flash('erreur', 'Le paiement n\'a pas abouti. Tu peux réessayer tant que le créneau est bloqué.'),
    default     => flash('info', 'Ton paiement est en cours de traitement. Valide-le sur ton téléphone puis actualise cette page.'),
};

redirect($urlRecap);