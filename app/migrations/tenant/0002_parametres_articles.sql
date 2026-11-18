-- BarberPay — base d'un barber — 0002 : paramètres du salon et catalogue

-- Une seule ligne (id = 1). Créée par l'application au premier accès (le nom du salon vient du registre).
CREATE TABLE IF NOT EXISTS parametres (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    nom_salon VARCHAR(120) NOT NULL COMMENT 'Nom commercial (enseigne)',
    raison_sociale VARCHAR(160) NULL COMMENT 'Nom de l''entrepreneur ou de la société',
    adresse VARCHAR(190) NULL,
    code_postal VARCHAR(10) NULL,
    ville VARCHAR(100) NULL,
    telephone VARCHAR(30) NULL,
    siret CHAR(14) NULL,
    tva_intracom VARCHAR(20) NULL,
    regime_tva ENUM('franchise', 'assujetti') NOT NULL DEFAULT 'franchise',
    -- Seuils de franchise en base (art. 293 B du CGI), en centimes, modifiables chaque année.
    -- Valeurs 2026 : services 37 500 € (majoré 41 250 €), ventes 85 000 € (majoré 93 500 €).
    seuil_services INT UNSIGNED NOT NULL DEFAULT 3750000,
    seuil_services_majore INT UNSIGNED NOT NULL DEFAULT 4125000,
    seuil_ventes INT UNSIGNED NOT NULL DEFAULT 8500000,
    seuil_ventes_majore INT UNSIGNED NOT NULL DEFAULT 9350000,
    mention_ticket VARCHAR(255) NULL COMMENT 'Texte libre en bas du ticket',
    animation_poteau TINYINT(1) NOT NULL DEFAULT 1,
    modifie_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT ck_parametres_une_ligne CHECK (id = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Services et produits. On ne supprime pas un article : on le désactive (il pourra figurer sur des tickets).
CREATE TABLE IF NOT EXISTS articles (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    type ENUM('service', 'produit') NOT NULL,
    nom VARCHAR(80) NOT NULL,
    prix_ttc INT UNSIGNED NOT NULL COMMENT 'Centimes',
    taux_tva SMALLINT UNSIGNED NOT NULL DEFAULT 2000 COMMENT 'Centièmes de % : 2000 = 20 %, 550 = 5,5 %',
    duree_minutes SMALLINT UNSIGNED NULL COMMENT 'Services',
    stock INT NULL COMMENT 'Produits ; NULL = stock non suivi',
    stock_alerte INT UNSIGNED NULL COMMENT 'Produits : alerte quand stock <= cette valeur',
    code_barre VARCHAR(64) NULL,
    ordre INT UNSIGNED NOT NULL DEFAULT 0,
    actif TINYINT(1) NOT NULL DEFAULT 1,
    cree_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    modifie_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_articles_code_barre (code_barre),
    KEY ix_articles_type_ordre (type, actif, ordre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
