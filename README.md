# Beauty

Site de réservation de soins (visage, manucure, pédicure) en PHP natif + MySQL,
avec acompte facultatif par Mobile Money (FedaPay) et emails (Brevo).

## Structure

```
admin/      espace d'administration (tableau de bord, réservations, soins, praticiennes, horaires)
config/     configuration (config.php = privé, config.example.php = modèle)
includes/   code partagé (connexion, sessions, réservation, paiement, emails)
logs/       journaux et emails enregistrés en local (non versionné)
public/     pages visibles par les clientes
sql/        schéma de la base et scripts
```

## Installation

1. Copier `config/config.example.php` en `config/config.php` et le remplir.
2. Créer une base MySQL vide et y importer `sql/schema.sql`.
3. Créer le compte administrateur : `php sql/creer_admin.php`
4. Ouvrir l'adresse du site : la racine redirige vers `public/`.

Prérequis : PHP 8.1+, extensions `pdo_mysql` et `curl`, Apache avec `.htaccess` actif.

## Sécurité

- `config/config.php` contient les mots de passe et clés : il est ignoré par Git, ne jamais le forcer dans un commit.
- Les dossiers `config/`, `includes/`, `sql/` et `logs/` sont fermés au web par leurs `.htaccess`.

## Sauvegardes

`taches/sauvegarde.php` fait un dump compressé de la base (et des photos le dimanche).
Tâche planifiée AlwaysData, tous les jours : `php $HOME/www/jess-nails/taches/sauvegarde.php`
Fichiers dans `$HOME/sauvegardes-beauty/` (14 jours + le 1er de chaque mois pendant 6 mois).
Lister : `php taches/sauvegarde.php --liste`

Restaurer (écrase les données actuelles) :

```
gzip -dc base_AAAA-MM-JJ_HH-MM.sql.gz | mysql --default-character-set=utf8mb4 -h HOTE -u UTILISATEUR -p NOM_BASE
```

## Protection contre les abus

Les tentatives de connexion, d'inscription, de mot de passe oublié et de suggestion sont comptées
en base (`limites_requetes`, migration `sql/etape23_limites.sql`). Réglages dans `public/connexion.php`
(constantes `LIMITE_*`) et dans les autres pages.
