<?php
/**
 * Racine du site : renvoie vers l'accueil public.
 * tondomaine.com  ->  tondomaine.com/public/
 */
header('Location: public/', true, 301);
exit;
