<?php
/**
 * Limitation des tentatives, enregistrée en base (donc impossible à contourner
 * en supprimant ses cookies, contrairement à un compteur de session).
 *
 *   if (limite_atteinte('connexion_ip', ip_client(), 20, 900)) { ... refuser ... }
 *   limite_noter('connexion_ip', ip_client());      // à appeler après un échec
 *   limite_effacer('connexion_email', $email);      // à appeler après un succès
 *
 * Les clés (IP, email) sont stockées sous forme d'empreinte SHA-256.
 * Si la table n'existe pas encore (migration oubliée), le site continue de
 * fonctionner sans limitation et l'erreur est écrite dans les logs.
 */

/** Adresse IP du visiteur (REMOTE_ADDR : la seule valeur non falsifiable par le visiteur). */
function ip_client(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function limite_cle(string $valeur): string
{
    return hash('sha256', mb_strtolower(trim($valeur)));
}

/** Vrai si $max tentatives ou plus ont été notées dans les $fenetreSecondes dernières secondes. */
function limite_atteinte(string $action, string $valeur, int $max, int $fenetreSecondes): bool
{
    try {
        $stmt = db()->prepare(
            'SELECT COUNT(*) FROM limites_requetes
             WHERE action = ? AND cle = ? AND created_at > NOW() - INTERVAL ' . (int) $fenetreSecondes . ' SECOND'
        );
        $stmt->execute([$action, limite_cle($valeur)]);
        return (int) $stmt->fetchColumn() >= $max;
    } catch (PDOException $e) {
        error_log('limites_requetes : ' . $e->getMessage());
        return false;
    }
}

function limite_noter(string $action, string $valeur): void
{
    try {
        db()->prepare('INSERT INTO limites_requetes (action, cle) VALUES (?, ?)')
            ->execute([$action, limite_cle($valeur)]);

        // Ménage : une fois de temps en temps, on supprime ce qui a plus de 2 jours
        if (random_int(1, 50) === 1) {
            db()->exec('DELETE FROM limites_requetes WHERE created_at < NOW() - INTERVAL 2 DAY');
        }
    } catch (PDOException $e) {
        error_log('limites_requetes : ' . $e->getMessage());
    }
}

function limite_effacer(string $action, string $valeur): void
{
    try {
        db()->prepare('DELETE FROM limites_requetes WHERE action = ? AND cle = ?')
            ->execute([$action, limite_cle($valeur)]);
    } catch (PDOException $e) {
        error_log('limites_requetes : ' . $e->getMessage());
    }
}
