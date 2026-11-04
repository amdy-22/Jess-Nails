-- Étape 22 : messages envoyés par le formulaire de contact
-- À exécuter UNE fois sur chaque base (relancer ne casse rien).

CREATE TABLE IF NOT EXISTS messages_contact (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  utilisateur_id INT UNSIGNED NULL,                 -- renseigné si la personne était connectée
  nom           VARCHAR(80)  NOT NULL,
  email         VARCHAR(190) NULL,
  telephone     VARCHAR(30)  NULL,
  sujet         VARCHAR(40)  NOT NULL,
  message       TEXT         NOT NULL,
  ip            VARCHAR(45)  NULL,                  -- limite anti-abus (5 messages par heure)
  lu            TINYINT(1)   NOT NULL DEFAULT 0,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_mc_lu (lu, created_at),
  INDEX idx_mc_ip (ip, created_at),
  CONSTRAINT fk_mc_utilisateur FOREIGN KEY (utilisateur_id) REFERENCES utilisateurs(id) ON DELETE SET NULL
) ENGINE=InnoDB;
