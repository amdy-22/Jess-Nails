-- Réglages modifiables depuis l'admin (ex. interrupteur de l'acompte en ligne)
CREATE TABLE IF NOT EXISTS parametres (
    cle        VARCHAR(60)  NOT NULL PRIMARY KEY,
    valeur     VARCHAR(255) NOT NULL,
    updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Acompte en ligne actif par défaut (comportement actuel)
INSERT IGNORE INTO parametres (cle, valeur) VALUES ('acompte_en_ligne', '1');
