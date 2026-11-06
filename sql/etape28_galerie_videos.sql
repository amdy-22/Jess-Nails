-- Étape 28 : vidéos dans la galerie
-- Ajoute le type (photo ou vidéo) aux éléments de la galerie. Peut être relancé sans risque.

SET @existe := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'photos' AND COLUMN_NAME = 'type');
SET @sql := IF(@existe = 0,
    "ALTER TABLE photos ADD COLUMN type ENUM('photo','video') NOT NULL DEFAULT 'photo' AFTER fichier",
    'SELECT 1');
PREPARE etape FROM @sql;
EXECUTE etape;
DEALLOCATE PREPARE etape;
