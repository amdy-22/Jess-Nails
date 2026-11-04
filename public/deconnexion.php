<?php
require_once __DIR__ . '/../includes/auth.php';

// La déconnexion passe par un formulaire POST (avec jeton CSRF),
// pour qu'un simple lien piégé ne puisse pas déconnecter quelqu'un.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(BASE_URL . '/');
}

csrf_verify();
logout_user();

// Nouvelle session vide (nouvel identifiant), juste pour afficher le message
session_id(session_create_id());
session_start();
flash('info', 'Déconnecté(e). À bientôt !');

redirect(BASE_URL . '/');