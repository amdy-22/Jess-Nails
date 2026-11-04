<?php
/**
 * MODÈLE de configuration du site Beauty.
 *
 * 1. Copie ce fichier en config.php (dans le même dossier)
 * 2. Remplis config.php avec tes vraies valeurs
 *
 * config.php est ignoré par Git (.gitignore) : tes mots de passe et clés
 * ne partent jamais sur GitHub. Ce modèle, lui, ne contient aucun secret.
 */

// ---------- Application ----------
define('APP_NAME', 'Jess Nails');
define('APP_ENV', 'dev');                 // 'dev' en local, 'prod' en ligne (cache les erreurs, cookie HTTPS)
date_default_timezone_set('Africa/Porto-Novo');

// URL de base (sans slash final)
// Local : http://localhost/beauty/public   |   En ligne : https://tondomaine.com/public
define('BASE_URL',  'http://localhost/beauty/public');
define('ADMIN_URL', 'http://localhost/beauty/admin');

// ---------- Base de données ----------
define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_NAME', 'Beauty');
define('DB_USER', 'root');
define('DB_PASS', '');                    // vide par défaut sous WAMP, OBLIGATOIRE en ligne

// ---------- Paiement Mobile Money : FedaPay ----------
// Clés dans ton tableau de bord FedaPay > Développeurs (ou Paramètres > API).
// En mode test, la clé secrète commence par sk_sandbox_
define('PAYMENT_PROVIDER', 'fedapay');
define('PAYMENT_SANDBOX', true);            // true = test, false = vrais paiements
define('PAYMENT_SECRET_KEY', '');           // sk_sandbox_... (ne jamais la mettre dans le JavaScript)
define('PAYMENT_WEBHOOK_SECRET', '');       // wh_sandbox_... (secret du webhook, une fois le site en ligne)
define('FEDAPAY_API_BASE', '');             // laisser vide (sert uniquement aux tests)

// ---------- Emails (Brevo) ----------
// Clé API : Brevo > menu du compte > SMTP & API > Clés API (commence par xkeysib-)
// Laisse vide en local : les emails seront enregistrés dans le dossier logs/emails/
define('BREVO_API_KEY', '');
define('BREVO_API_BASE', '');                  // laisser vide (sert uniquement aux tests)
define('MAIL_FROM_EMAIL', 'jessicabakary048@gmail.com');  // expéditeur validé dans Brevo (à changer)
define('MAIL_FROM_NAME', APP_NAME);
define('ADMIN_NOTIFICATION_EMAIL', '');       // vide = tous les comptes admin reçoivent l'email

// Infos de l'institut affichées dans l'email de confirmation (facultatif)
define('SALON_ADRESSE', '');                  // ex. 'Haie Vive, Cotonou'
define('SALON_TELEPHONE', '');                // ex. '01 97 00 00 00'

// ---------- Erreurs PHP ----------
// En dev : affichées à l'écran. En prod : jamais affichées, mais enregistrées dans logs/php-errors.log
error_reporting(E_ALL);
if (APP_ENV === 'dev') {
    ini_set('display_errors', '1');
} else {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    ini_set('error_log', __DIR__ . '/../logs/php-errors.log');
}

define('DEVELOPPEUR_EMAIL', 'alexamdy22@gmail.com');