<?php
/**
 * En-tête commun. Avant de l'inclure, une page peut définir :
 *   $pageTitle  : titre de l'onglet
 *   $activePage : 'accueil', 'prestations', 'reserver', 'compte', 'connexion', 'inscription'
 */
$pageTitle  = $pageTitle  ?? APP_NAME;
$activePage = $activePage ?? '';
$user       = current_user();

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
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body>

<header class="site-header">
    <div class="conteneur header-inner">
        <a href="<?= BASE_URL ?>/index.php" class="logo"><?= e(APP_NAME) ?></a>

        <button class="menu-toggle" type="button" aria-expanded="false" aria-controls="menu-principal">
            Menu
        </button>

        <nav id="menu-principal" class="nav">
            <ul class="nav-liens">
                <li><a href="<?= BASE_URL ?>/index.php"<?= nav_class('accueil', $activePage) ?>>Accueil</a></li>
                <li><a href="<?= BASE_URL ?>/prestations.php"<?= nav_class('prestations', $activePage) ?>>Prestations</a></li>
                <li><a href="<?= BASE_URL ?>/reserver.php"<?= nav_class('reserver', $activePage) ?>>Réserver</a></li>
            </ul>

            <ul class="nav-compte">
                <?php if ($user): ?>
                    <?php if ($user['role'] === 'admin'): ?>
                        <li><a href="<?= ADMIN_URL ?>/index.php">Administration</a></li>
                    <?php endif; ?>
                    <li><a href="<?= BASE_URL ?>/profile.php"<?= nav_class('compte', $activePage) ?>>
                        <?= e($user['prenom']) ?></a></li>
                    <li>
                        <form action="<?= BASE_URL ?>/deconnexion.php" method="post" class="form-inline">
                            <?= csrf_field() ?>
                            <button type="submit" class="lien-bouton">Déconnexion</button>
                        </form>
                    </li>
                <?php else: ?>
                    <li><a href="<?= BASE_URL ?>/connexion.php"<?= nav_class('connexion', $activePage) ?>>Connexion</a></li>
                    <li><a href="<?= BASE_URL ?>/inscription.php" class="btn btn-petit">Créer un compte</a></li>
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