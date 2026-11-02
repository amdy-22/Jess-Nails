-- =====================================================
-- Étape 11 : rappel automatique avant le rendez-vous
-- À exécuter UNE fois sur chaque base (locale ET en ligne).
-- =====================================================

-- Date d'envoi du rappel (NULL = pas encore envoyé)
ALTER TABLE reservations
  ADD COLUMN rappel_envoye_le DATETIME NULL AFTER source;

-- Le script de rappel cherche par statut et par heure de début
CREATE INDEX idx_reservations_rappel ON reservations (statut, debut);
