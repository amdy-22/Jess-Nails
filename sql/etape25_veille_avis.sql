-- Étape 25 : rappel de la veille + avis des clientes
-- À exécuter UNE fois sur chaque base (locale ET en ligne).

-- Rappel WhatsApp de la veille (NULL = pas encore fait) et demande d'avis par e-mail (NULL = pas encore envoyée)
ALTER TABLE reservations
  ADD COLUMN rappel_veille_le DATETIME NULL AFTER rappel_whatsapp_le,
  ADD COLUMN avis_demande_le  DATETIME NULL AFTER rappel_veille_le;

-- Un avis par rendez-vous terminé. Il n'est visible sur le site qu'après validation par l'admin.
CREATE TABLE IF NOT EXISTS avis (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reservation_id INT UNSIGNED NOT NULL,
  client_id      INT UNSIGNED NOT NULL,
  note           TINYINT UNSIGNED NOT NULL,                      -- 1 à 5 étoiles
  commentaire    VARCHAR(600) NULL,
  statut         ENUM('en_attente','publie','masque') NOT NULL DEFAULT 'en_attente',
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_avis_reservation (reservation_id),
  INDEX idx_avis_statut (statut, created_at),
  CONSTRAINT fk_avis_reservation FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE CASCADE,
  CONSTRAINT fk_avis_client      FOREIGN KEY (client_id)      REFERENCES utilisateurs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
