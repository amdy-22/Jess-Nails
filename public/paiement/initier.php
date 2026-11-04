<?php
/**
 * Récapitulatif de la réservation + choix du mode de règlement.
 *   - "payer"     : acompte en ligne via FedaPay (facultatif)
 *   - "sur_place" : confirmation immédiate, tout se règle à l'institut
 * Une réservation confirmée sans acompte peut encore payer l'acompte plus tard.
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/reservation.php';
require_once __DIR__ . '/../../includes/fedapay.php';
require_login();

$client = current_user();
$id     = (int) ($_POST['reservation'] ?? $_GET['reservation'] ?? 0);

$stmt = db()->prepare(
    'SELECT r.*, COALESCE(r.libelle, p.nom) AS soin, TIMESTAMPDIFF(MINUTE, r.debut, r.fin) AS duree_minutes, pr.nom AS praticienne
     FROM reservations r
     JOIN prestations p   ON p.id  = r.prestation_id
     JOIN praticiennes pr ON pr.id = r.praticienne_id
     WHERE r.id = ? AND r.client_id = ?'
);
$stmt->execute([$id, $client['id']]);
$resa = $stmt->fetch();

if (!$resa) {
    flash('erreur', 'Réservation introuvable.');
    redirect(BASE_URL . '/reserver');
}

$urlRecap   = BASE_URL . '/paiement/initier?reservation=' . (int) $resa['id'];
$debut      = new DateTimeImmutable($resa['debut']);
$acompte    = (int) ($resa['acompte_fcfa'] > 0 ? $resa['acompte_fcfa'] : $resa['prix_fcfa']);
$dejaPaye   = montant_paye((int) $resa['id']);
$enAttente  = $resa['statut'] === 'en_attente_paiement';
$bloquee    = $enAttente && reservation_encore_bloquee((int) $resa['id']);
$confirmee  = $resa['statut'] === 'confirmee';
$aVenir     = $debut > new DateTimeImmutable();

// L'acompte en ligne est proposé : avant confirmation, ou plus tard si rien n'a été payé
$peutPayer  = $aVenir && $dejaPaye === 0 && ($bloquee || $confirmee);

// =====================================================
// Actions
// =====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? 'payer';

    // ---------- Confirmer sans acompte ----------
    if ($action === 'sur_place') {
        if (!$enAttente) {
            redirect($urlRecap);
        }
        if (!$bloquee) {
            flash('erreur', 'Le délai est dépassé, ce créneau n\'est plus bloqué. Choisis un nouveau créneau.');
            redirect($urlRecap);
        }
        $maj = db()->prepare("UPDATE reservations SET statut = 'confirmee' WHERE id = ? AND statut = 'en_attente_paiement'");
        $maj->execute([$resa['id']]);
        if ($maj->rowCount() > 0) {
            try {
                notifier_reservation_confirmee((int) $resa['id']);
            } catch (Throwable $e) {
                error_log('[Emails] ' . $e->getMessage());
            }
            flash('succes', 'Ta réservation est confirmée. Tu régleras ' . format_fcfa((int) $resa['prix_fcfa']) . ' sur place.');
        }
        redirect($urlRecap);
    }

    // ---------- Payer l'acompte en ligne ----------
    if (!$peutPayer) {
        flash('erreur', $enAttente && !$bloquee
            ? 'Le délai est dépassé, ce créneau n\'est plus bloqué. Choisis un nouveau créneau.'
            : 'Le paiement en ligne n\'est plus disponible pour cette réservation.');
        redirect($urlRecap);
    }
    if (!fedapay_est_configure()) {
        flash('erreur', 'Le paiement en ligne n\'est pas encore configuré (clé FedaPay manquante dans config.php).');
        redirect($urlRecap);
    }

    try {
        $tx = fedapay_creer_paiement($resa, $client, $acompte);
    } catch (RuntimeException $e) {
        error_log('[FedaPay] ' . $e->getMessage());
        flash('erreur', 'Le service de paiement ne répond pas pour le moment. Réessaie dans un instant.'
            . (APP_ENV === 'dev' ? ' (' . $e->getMessage() . ')' : ''));
        redirect($urlRecap);
    }

    db()->prepare(
        "INSERT INTO paiements (reservation_id, fournisseur, transaction_ref, montant_fcfa, statut)
         VALUES (?, 'fedapay', ?, ?, 'en_attente')"
    )->execute([$resa['id'], $tx['id'], $acompte]);

    redirect($tx['url']);   // page de paiement FedaPay (MTN, Moov, carte...)
}

// =====================================================
// Affichage
// =====================================================
$expireA = (new DateTimeImmutable($resa['created_at']))->modify('+' . EXPIRATION_ATTENTE . ' minutes');
$dernier = db()->prepare("SELECT MAX(created_at) FROM paiements WHERE reservation_id = ? AND statut = 'en_attente'");
$dernier->execute([$resa['id']]);
if ($dateDernier = $dernier->fetchColumn()) {   // un paiement lancé récemment prolonge le délai
    $expireA = max($expireA, (new DateTimeImmutable($dateDernier))->modify('+' . PAIEMENT_EN_COURS . ' minutes'));
}
$resteSurPlace = max(0, (int) $resa['prix_fcfa'] - $dejaPaye);

$pageTitle = 'Récapitulatif';
require __DIR__ . '/../../includes/header.php';
?>

<section class="carte-formulaire">
    <h1>Ta réservation</h1>

    <dl class="recap">
        <div><dt>Soin</dt><dd><?= e($resa['soin']) ?> (<?= format_duree((int) $resa['duree_minutes']) ?>)</dd></div>
        <div><dt>Date</dt><dd><?= e(format_date_fr($debut, true)) ?></dd></div>
        <div><dt>Avec</dt><dd><?= e($resa['praticienne']) ?></dd></div>
        <div><dt>Prix</dt><dd><?= format_fcfa((int) $resa['prix_fcfa']) ?></dd></div>
        <?php if ($dejaPaye > 0): ?>
            <div><dt>Acompte payé</dt><dd class="prix"><?= format_fcfa($dejaPaye) ?></dd></div>
        <?php endif; ?>
        <?php if ($confirmee): ?>
            <div><dt>À régler sur place</dt><dd><?= format_fcfa($resteSurPlace) ?></dd></div>
        <?php endif; ?>
        <?php if ($resa['note_client']): ?>
            <div><dt>Ta précision</dt><dd><?= e($resa['note_client']) ?></dd></div>
        <?php endif; ?>
    </dl>

    <?php if ($confirmee): ?>
        <p class="alerte alerte-succes">Réservation confirmée. On t'attend le <?= e(format_date_fr($debut, true)) ?> !</p>

        <?php if ($peutPayer): ?>
            <div class="choix-option">
                <p>Tu peux régler l'acompte maintenant pour gagner du temps le jour J (facultatif).</p>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="reservation" value="<?= (int) $resa['id'] ?>">
                    <input type="hidden" name="action" value="payer">
                    <button type="submit" class="btn">Payer l'acompte de <?= format_fcfa($acompte) ?></button>
                </form>
            </div>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/profile" class="btn btn-plein btn-large">Voir mes rendez-vous</a>

    <?php elseif ($resa['statut'] === 'annulee'): ?>
        <p class="alerte alerte-erreur">Cette réservation a été annulée.</p>
        <a href="<?= BASE_URL ?>/reserver" class="btn btn-plein">Réserver un autre créneau</a>

    <?php elseif (!$bloquee): ?>
        <p class="alerte alerte-erreur">Ce créneau n'est plus bloqué car la réservation n'a pas été confirmée à temps.</p>
        <a href="<?= BASE_URL ?>/reserver?prestation=<?= (int) $resa['prestation_id'] ?>" class="btn btn-plein">Choisir un nouveau créneau</a>

    <?php else: ?>
        <p class="alerte alerte-info">
            Ce créneau t'est réservé jusqu'à <?= $expireA->format('H\hi') ?>. Choisis comment tu veux régler pour confirmer.
        </p>

                <?php require __DIR__ . '/../../includes/conditions_resume.php'; ?>
        <div class="choix-reglement">
            <form method="post" class="choix">
                <?= csrf_field() ?>
                <input type="hidden" name="reservation" value="<?= (int) $resa['id'] ?>">
                <input type="hidden" name="action" value="payer">
                <p class="choix-titre">Payer l'acompte maintenant</p>
                <p class="discret"><?= format_fcfa($acompte) ?> par Mobile Money ou carte, le reste (<?= format_fcfa((int) $resa['prix_fcfa'] - $acompte) ?>) sur place.</p>
                <button type="submit" class="btn btn-plein">Payer <?= format_fcfa($acompte) ?></button>
            </form>
            <p class="discret petit-texte">Paiement en ligne sécurisé par FedaPay : MTN MoMo, Moov Money ou carte bancaire.</p>

            <form method="post" class="choix">
                <?= csrf_field() ?>
                <input type="hidden" name="reservation" value="<?= (int) $resa['id'] ?>">
                <input type="hidden" name="action" value="sur_place">
                <p class="choix-titre">Tout régler sur place</p>
                <p class="discret">Aucun paiement maintenant. Tu règles <?= format_fcfa((int) $resa['prix_fcfa']) ?> à l'institut.</p>
                <button type="submit" class="btn">Confirmer sans payer</button>
            </form>
        </div>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/../../includes/footer.php';