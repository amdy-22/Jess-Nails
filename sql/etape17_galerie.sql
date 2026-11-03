-- Étape 17 : galerie photos
-- À exécuter UNE fois sur chaque base (relancer ne casse rien).

CREATE TABLE IF NOT EXISTS photos (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  fichier       VARCHAR(60) NOT NULL,              -- nom aléatoire, ex. 3f9a...c2.jpg
  largeur       SMALLINT UNSIGNED NOT NULL,        -- taille de la grande image (pour éviter les sauts d'affichage)
  hauteur       SMALLINT UNSIGNED NOT NULL,
  legende       VARCHAR(150) NULL,
  categorie_id  INT UNSIGNED NULL,                 -- NULL = photo générale (institut, ambiance)
  ordre         INT NOT NULL DEFAULT 0,            -- plus grand = affiché en premier
  visible       TINYINT(1) NOT NULL DEFAULT 1,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_photos_fichier (fichier),
  INDEX idx_photos_affichage (visible, ordre),
  CONSTRAINT fk_photos_categorie FOREIGN KEY (categorie_id) REFERENCES categories(id) ON DELETE SET NULL
) ENGINE=InnoDB;
