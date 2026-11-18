-- BarberPay — base d'un barber — 0001 : utilisateurs et sécurité de connexion

CREATE TABLE IF NOT EXISTS utilisateurs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    prenom VARCHAR(60) NOT NULL,
    nom VARCHAR(60) NULL,
    email VARCHAR(190) NULL COMMENT 'Gérant uniquement (connexion à distance)',
    mot_de_passe VARCHAR(255) NULL COMMENT 'password_hash(), gérant uniquement',
    pin VARCHAR(255) NULL COMMENT 'password_hash() du code PIN (verrouillage de la caisse, étape 2)',
    role ENUM('gerant', 'barbier') NOT NULL DEFAULT 'barbier',
    couleur CHAR(7) NULL,
    actif TINYINT(1) NOT NULL DEFAULT 1,
    cree_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    modifie_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_utilisateurs_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Échecs de connexion récents (blocage après trop d'essais). Purgés après 24 h.
CREATE TABLE IF NOT EXISTS connexions_echecs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    cle CHAR(70) NOT NULL COMMENT 'email:<sha256> ou ip:<sha256> — jamais l''e-mail ou l''IP en clair',
    date_echec DATETIME NOT NULL,
    KEY ix_connexions_echecs_cle_date (cle, date_echec),
    KEY ix_connexions_echecs_date (date_echec)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
