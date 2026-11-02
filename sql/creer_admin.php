<?php
/**
 * Crée un compte administrateur.
 * À lancer UNE fois en ligne de commande :
 *   php sql\creer_admin.php
 */

if (PHP_SAPI !== 'cli') {
    exit('Ce script se lance uniquement en ligne de commande.');
}

require_once __DIR__ . '/../config/database.php';

function demander(string $question): string
{
    echo $question . ' : ';
    return trim((string) fgets(STDIN));
}

$prenom    = demander('Prénom');
$nom       = demander('Nom');
$email     = mb_strtolower(demander('Email'));
$telephone = demander('Téléphone');
$mdp       = demander('Mot de passe (8 caractères minimum)');

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    exit("Email invalide.\n");
}
if (strlen($mdp) < 8) {
    exit("Mot de passe trop court.\n");
}

$existe = db()->prepare('SELECT id FROM utilisateurs WHERE email = ?');
$existe->execute([$email]);
if ($existe->fetch()) {
    exit("Un compte existe déjà avec cet email.\n");
}

$stmt = db()->prepare(
    'INSERT INTO utilisateurs (nom, prenom, email, telephone, mot_de_passe, role)
     VALUES (?, ?, ?, ?, ?, \'admin\')'
);
$stmt->execute([$nom, $prenom, $email, $telephone, password_hash($mdp, PASSWORD_DEFAULT)]);

echo "Administrateur créé (id " . db()->lastInsertId() . ").\n";