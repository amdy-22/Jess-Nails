-- Étape 16 : plusieurs soins dans un même rendez-vous
-- À exécuter UNE fois sur une base existante (ne pas relancer schema.sql).

-- Libellé affiché partout, ex. « Manucure + Pédicure ». prestation_id reste le premier soin.
ALTER TABLE reservations
  ADD COLUMN libelle VARCHAR(255) NULL AFTER prestation_id;

UPDATE reservations r JOIN prestations p ON p.id = r.prestation_id
SET r.libelle = p.nom
WHERE r.libelle IS NULL;

-- Détail des soins d'un rendez-vous, avec prix et durées figés au moment de la réservation
CREATE TABLE reservation_prestations (
  reservation_id  INT UNSIGNED NOT NULL,
  ordre           TINYINT UNSIGNED NOT NULL,          -- 1 = premier soin réalisé
  prestation_id   INT UNSIGNED NOT NULL,
  nom             VARCHAR(120) NOT NULL,
  duree_minutes   SMALLINT UNSIGNED NOT NULL,
  prix_fcfa       INT UNSIGNED NOT NULL,
  acompte_fcfa    INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (reservation_id, ordre),
  INDEX idx_rp_prestation (prestation_id),
  CONSTRAINT fk_rp_reservation FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE CASCADE,
  CONSTRAINT fk_rp_prestation  FOREIGN KEY (prestation_id)  REFERENCES prestations(id)
) ENGINE=InnoDB;

-- Les rendez-vous existants ont un seul soin
INSERT INTO reservation_prestations (reservation_id, ordre, prestation_id, nom, duree_minutes, prix_fcfa, acompte_fcfa)
SELECT r.id, 1, r.prestation_id, p.nom, TIMESTAMPDIFF(MINUTE, r.debut, r.fin), r.prix_fcfa, r.acompte_fcfa
FROM reservations r JOIN prestations p ON p.id = r.prestation_id;
