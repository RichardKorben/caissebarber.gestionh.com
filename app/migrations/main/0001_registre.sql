-- BarberPay — base commune GH_main — 0001 : registre des barbers et noms réservés
-- Toutes les tables BarberPay de GH_main commencent par « barberpay_ ».

CREATE TABLE IF NOT EXISTS barberpay_comptes (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    sous_domaine VARCHAR(30) NOT NULL COMMENT 'barbermax pour barbermax.gestionh.com',
    base VARCHAR(64) NOT NULL COMMENT 'Base MariaDB du barber',
    nom_salon VARCHAR(120) NOT NULL,
    email_gerant VARCHAR(190) NULL,
    statut ENUM('preparation', 'actif', 'gele') NOT NULL DEFAULT 'preparation',
    version_schema INT UNSIGNED NOT NULL DEFAULT 0,
    version_attestation VARCHAR(20) NULL COMMENT 'Version du logiciel couverte par la dernière attestation remise',
    cle_publique VARCHAR(128) NULL COMMENT 'Clé publique Ed25519 (signature des tickets, étape 3)',
    cree_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    modifie_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_barberpay_comptes_sous_domaine (sous_domaine),
    UNIQUE KEY uq_barberpay_comptes_base (base)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS barberpay_noms_reserves (
    nom VARCHAR(30) NOT NULL PRIMARY KEY,
    motif VARCHAR(120) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO barberpay_noms_reserves (nom, motif) VALUES
    ('www', 'technique'), ('admin', 'technique'), ('api', 'technique'), ('mail', 'technique'),
    ('smtp', 'technique'), ('ftp', 'technique'), ('install', 'site existant'), ('demo', 'site existant'),
    ('test', 'technique'), ('dev', 'technique'), ('static', 'technique'), ('cdn', 'technique'),
    ('barberpay', 'marque'), ('support', 'marque'), ('aide', 'marque'), ('inscription', 'marque');
-- Les sous-domaines déjà utilisés sur le serveur (fichiers de /etc/apache2/sites-available/) sont
-- vérifiés en plus à l'inscription (étape 6) ; caissebarber est le compte de développement.
