-- Étape 23 : limitation des tentatives (anti force brute et anti spam)
-- À exécuter UNE fois sur chaque base (relancer ne casse rien).

CREATE TABLE IF NOT EXISTS limites_requetes (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  action     VARCHAR(30) NOT NULL,          -- ex. connexion_ip, connexion_email, inscription_ip
  cle        CHAR(64)    NOT NULL,          -- empreinte (SHA-256) de l'IP ou de l'email : rien n'est stocké en clair
  created_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_limites (action, cle, created_at),
  INDEX idx_limites_date (created_at)
) ENGINE=InnoDB;
