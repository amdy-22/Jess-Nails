<?php
/**
 * Réglages simples clé/valeur, modifiables depuis l'admin.
 * Tolérant : si la table n'existe pas encore, on renvoie la valeur par défaut (le site continue de fonctionner).
 */

function parametre(string $cle, string $defaut = ''): string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            foreach (db()->query('SELECT cle, valeur FROM parametres')->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) {
                $cache[$k] = $v;
            }
        } catch (Throwable $e) {
            // table absente : valeurs par défaut
        }
    }
    return $cache[$cle] ?? $defaut;
}

/** Renvoie false si l'enregistrement a échoué (table absente). */
function definir_parametre(string $cle, string $valeur): bool
{
    try {
        db()->prepare('INSERT INTO parametres (cle, valeur) VALUES (?, ?) ON DUPLICATE KEY UPDATE valeur = VALUES(valeur)')
            ->execute([$cle, $valeur]);
        return true;
    } catch (Throwable $e) {
        error_log('[Parametres] ' . $e->getMessage());
        return false;
    }
}

/**
 * L'acompte en ligne est-il proposé aux clientes ?
 * Désactivé : toute réservation est confirmée tout de suite, le règlement se fait à l'institut.
 */
function acompte_en_ligne_actif(): bool
{
    return parametre('acompte_en_ligne', '1') === '1';
}
