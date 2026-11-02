<?php
/**
 * Connexion PDO unique (créée au premier appel de db()).
 */

require_once __DIR__ . '/config.php';

function db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            DB_HOST, DB_PORT, DB_NAME
        );

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            // Aligne l'heure MySQL sur celle du Bénin (UTC+1)
            $pdo->exec("SET time_zone = '+01:00'");
        } catch (PDOException $e) {
            if (APP_ENV === 'dev') {
                die('Erreur de connexion à la base : ' . $e->getMessage());
            }
            die('Le service est momentanément indisponible.');
        }
    }

    return $pdo;
}