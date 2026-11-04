<?php
/**
 * En-tête commun. Avant de l'inclure, une page peut définir :
 *   $pageTitle  : titre de l'onglet
 *   $activePage : 'accueil', 'prestations', 'reserver', 'compte', 'connexion', 'inscription'
 */
$pageTitle  = $pageTitle  ?? APP_NAME;
$activePage = $activePage ?? '';
$user       = current_user();
require_once __DIR__ . '/icones.php';

function nav_class(string $page, string $active): string
{
    return $page === $active ? ' class="actif" aria-current="page"' : '';
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> | <?= e(APP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=Manrope:wght@400;500;600;700&display=swap" rel="stylesheet">
    <?php // ?v= change tout seul à chaque modification du CSS : plus besoin d'y penser ?>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css?v=<?= @filemtime(__DIR__ . '/../public/assets/css/style.css') ?: 1 ?>">
</head>
<body>

<header class="site-header entete-public">
    <div class="conteneur header-inner">
        <a href="<?= BASE_URL ?>/" class="logo"><?= e(APP_NAME) ?></a>

        <button class="menu-toggle" type="button" aria-expanded="false" aria-controls="menu-principal" aria-label="Ouvrir le menu">
            <span class="menu-icone" aria-hidden="true"><span></span><span></span><span></span></span>
            <span class="menu-texte">Menu</span>
        </button>

        <nav id="menu-principal" class="nav">
            <ul class="nav-liens">
                <li><a href="<?= BASE_URL ?>/"<?= nav_class('accueil', $activePage) ?>><?= icone('accueil') ?><span>Accueil</span></a></li>
                <li><a href="<?= BASE_URL ?>/prestations"<?= nav_class('prestations', $activePage) ?>><?= icone('soins') ?><span>Prestations</span></a></li>
                <li><a href="<?= BASE_URL ?>/reserver"<?= nav_class('reserver', $activePage) ?>><?= icone('calendrier') ?><span>Réserver</span></a></li>
                <li><a href="<?= BASE_URL ?>/galerie"<?= nav_class('galerie', $activePage) ?>><?= icone('galerie') ?><span>Galerie</span></a></li>
                <li><a href="<?= BASE_URL ?>/contact"<?= nav_class('contact', $activePage) ?>><?= icone('contact') ?><span>Contact</span></a></li>
                <?php if ($user && $user['role'] === 'admin'): ?>
                    <li><a href="<?= ADMIN_URL ?>/"><?= icone('admin') ?><span>Admin</span></a></li>
                <?php endif; ?>
            </ul>

            <ul class="nav-compte">
                <?php if ($user): ?>
                    <li>
                        <a href="<?= BASE_URL ?>/profile" class="avatar-lien<?= $activePage === 'compte' ? ' actif' : '' ?>" title="Mon compte">
                            <span class="avatar" aria-hidden="true"><?= e(initiales($user)) ?></span>
                            <!--span class="avatar-texte">Mon compte · <?= e($user['prenom']) ?></span-->
                        </a>
                    </li>
                    <li>
                        <form action="<?= BASE_URL ?>/deconnexion" method="post" class="form-inline">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn-deconnexion" aria-label="Déconnexion"><?= icone('deconnexion', 16) ?><span>Déconnexion</span></button>
                        </form>
                    </li>
                <?php else: ?>
                    <li><a href="<?= BASE_URL ?>/connexion" class="lien-connexion<?= $activePage === 'connexion' ? ' actif' : '' ?>"><?= icone('connexion', 16) ?><span>Connexion</span></a></li>
                    <li><a href="<?= BASE_URL ?>/inscription" class="btn btn-petit btn-inscription"><?= icone('inscription', 16) ?><span>Créer un compte</span></a></li>
                <?php endif; ?>
            </ul>
        </nav>
    </div>
</header>

<main class="site-main">
    <div class="conteneur">
        <?php foreach (get_flashes() as $flash): ?>
            <div class="alerte alerte-<?= e($flash['type']) ?>" role="status">
                <?= e($flash['message']) ?>
            </div>
        <?php endforeach; ?>
