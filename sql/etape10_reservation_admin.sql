-- =====================================================
-- Étape 10 : réservations saisies par l'admin
-- (téléphone, WhatsApp, cliente sans rendez-vous)
--
-- À exécuter UNE fois sur chaque base (locale ET en ligne).
-- Ne contient pas de "USE" : sélectionne d'abord la base dans phpMyAdmin,
-- ou passe son nom à la commande mysql.
-- =====================================================

-- Une cliente créée par l'institut peut ne pas avoir d'email ni de mot de passe
ALTER TABLE utilisateurs
  MODIFY email        VARCHAR(150) NULL,
  MODIFY mot_de_passe VARCHAR(255) NULL;

-- Origine du rendez-vous
ALTER TABLE reservations
  ADD COLUMN source ENUM('en_ligne','telephone','whatsapp','sur_place')
      NOT NULL DEFAULT 'en_ligne' AFTER statut;

-- Recherche rapide d'une cliente par téléphone
CREATE INDEX idx_utilisateurs_telephone ON utilisateurs (telephone);
