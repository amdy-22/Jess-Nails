<?php
/**
 * En-tête de l'espace admin. Avant de l'inclure, une page peut définir :
 *   $pageTitle   : titre de l'onglet
 *   $activeAdmin : 'tableau', 'reservations', 'prestations', 'praticiennes', 'horaires'
 */
$pageTitle   = $pageTitle   ?? 'Administration';
$activeAdmin = $activeAdmin ?? '';
$admin       = current_user();

function admin_lien(string $page, string $fichier, string $libelle, string $actif, int $compteur = 0): string
{
    $classe = $page === $actif ? ' class="actif" aria-current="page"' : '';
    $pastille = $compteur > 0 ? ' <span class="compteur-nav" aria-label="' . $compteur . ' non lus">' . $compteur . '</span>' : '';
    return '<li><a href="' . ADMIN_URL . '/' . $fichier . '"' . $classe . '>' . e($libelle) . $pastille . '</a></li>';
}

// Messages de contact non lus (0 si la table n'existe pas encore)
try {
    $messagesNonLus = (int) db()->query('SELECT COUNT(*) FROM messages_contact WHERE lu = 0')->fetchColumn();
} catch (Throwable $e) {
    $messagesNonLus = 0;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title><?= e($pageTitle) ?> | Admin <?= e(APP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=Manrope:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css?v=<?= @filemtime(__DIR__ . '/../public/assets/css/style.css') ?: 1 ?>">
</head>
<body class="admin">

<header class="site-header admin-header">
    <div class="conteneur conteneur-large header-inner">
        <a href="<?= ADMIN_URL ?>/" class="logo"><?= e(APP_NAME) ?> <span class="logo-admin">admin</span></a>

        <button class="menu-toggle" type="button" aria-expanded="false" aria-controls="menu-principal" aria-label="Ouvrir le menu">
            <span class="menu-icone" aria-hidden="true"><span></span><span></span><span></span></span>
            <span class="menu-texte">Menu</span>
        </button>

        <nav id="menu-principal" class="nav">
            <ul class="nav-liens">
                <?= admin_lien('tableau', '', 'Tableau de bord', $activeAdmin) ?>
                <?= admin_lien('reservations', 'reservations', 'Réservations', $activeAdmin) ?>
                <?= admin_lien('prestations', 'prestations', 'Prestations', $activeAdmin) ?>
                <?= admin_lien('galerie', 'galerie', 'Galerie', $activeAdmin) ?>
                <?= admin_lien('praticiennes', 'praticiennes', 'Praticiennes', $activeAdmin) ?>
                <?= admin_lien('horaires', 'horaires', 'Horaires', $activeAdmin) ?>
            </ul>
            <ul class="nav-compte">
                <li><a href="<?= BASE_URL ?>/">Voir le site</a></li>
                <li>
                    <form action="<?= BASE_URL ?>/deconnexion" method="post" class="form-inline">
                        <?= csrf_field() ?>
                        <button type="submit" class="lien-bouton">Déconnexion (<?= e($admin['prenom']) ?>)</button>
                    </form>
                </li>
            </ul>
        </nav>
    </div>
</header>

<main class="site-main">
    <div class="conteneur conteneur-large">
        <?php foreach (get_flashes() as $flash): ?>
            <div class="alerte alerte-<?= e($flash['type']) ?>" role="status"><?= e($flash['message']) ?></div>
        <?php endforeach; ?>
