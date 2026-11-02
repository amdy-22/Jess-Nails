-- =====================================================
-- Étape 12 : planning par praticienne (jours, horaires, pause, congés)
-- À exécuter UNE fois sur chaque base.
-- =====================================================

-- Semaine type d'une praticienne.
-- Aucune ligne pour une praticienne = elle suit les horaires de l'institut, sans pause.
-- heure_debut NULL = elle ne travaille pas ce jour-là.
CREATE TABLE IF NOT EXISTS praticienne_horaires (
  praticienne_id INT UNSIGNED NOT NULL,
  jour_semaine   TINYINT UNSIGNED NOT NULL,      -- 1 = lundi ... 7 = dimanche
  heure_debut    TIME NULL,
  heure_fin      TIME NULL,
  pause_debut    TIME NULL,
  pause_fin      TIME NULL,
  PRIMARY KEY (praticienne_id, jour_semaine),
  CONSTRAINT fk_ph_praticienne FOREIGN KEY (praticienne_id) REFERENCES praticiennes(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Congés et absences ponctuelles (dates incluses)
CREATE TABLE IF NOT EXISTS praticienne_absences (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  praticienne_id INT UNSIGNED NOT NULL,
  date_debut     DATE NOT NULL,
  date_fin       DATE NOT NULL,
  motif          VARCHAR(150) NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_pa_praticienne FOREIGN KEY (praticienne_id) REFERENCES praticiennes(id) ON DELETE CASCADE,
  INDEX idx_pa_periode (praticienne_id, date_debut, date_fin)
) ENGINE=InnoDB;
