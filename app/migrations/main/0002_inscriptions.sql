-- BarberPay — base commune GH_main — 0002 : inscriptions en ligne depuis https://barberpay.fr/inscription
-- Écrites et lues par le script root /var/kdSite/scriptsV2/barber_inscription.py (dispatcher, action barber_inscription).

CREATE TABLE IF NOT EXISTS barberpay_inscriptions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    jeton CHAR(32) NOT NULL COMMENT 'Lien de confirmation envoyé par e-mail',
    jeton_suivi CHAR(24) NULL COMMENT 'Suivi de la création (page publique)',
    siret CHAR(14) NOT NULL,
    sous_domaine VARCHAR(30) NOT NULL,
    nom_salon VARCHAR(120) NOT NULL,
    regime_tva ENUM('franchise', 'assujetti') NOT NULL,
    prenom VARCHAR(100) NOT NULL,
    nom VARCHAR(100) NOT NULL,
    email VARCHAR(190) NOT NULL,
    telephone VARCHAR(30) NULL,
    fiche JSON NOT NULL COMMENT 'Fiche de l''annuaire des entreprises au moment de la demande',
    ip VARCHAR(45) NOT NULL,
    statut ENUM('attente', 'creation', 'creee', 'echec', 'expiree') NOT NULL DEFAULT 'attente',
    message VARCHAR(500) NULL COMMENT 'Motif d''échec (interne)',
    cree_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    confirme_le DATETIME NULL,
    termine_le DATETIME NULL,
    UNIQUE KEY uq_barberpay_inscriptions_jeton (jeton),
    UNIQUE KEY uq_barberpay_inscriptions_suivi (jeton_suivi),
    KEY ix_barberpay_inscriptions_siret (siret, statut),
    KEY ix_barberpay_inscriptions_sous_domaine (sous_domaine, statut),
    KEY ix_barberpay_inscriptions_date (cree_le)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Recherches dans l'annuaire par adresse IP (limite contre les abus, purgées après 24 h par le script).
CREATE TABLE IF NOT EXISTS barberpay_inscriptions_recherches (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ip VARCHAR(45) NOT NULL,
    cree_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_barberpay_recherches_ip (ip, cree_le)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Noms réservés supplémentaires (sites du serveur et marques).
INSERT IGNORE INTO barberpay_noms_reserves (nom, motif) VALUES
    ('barberdemo', 'démo BarberPay'), ('caissebarber', 'compte de développement'), ('caisse', 'technique'),
    ('gestion', 'technique'), ('rdv', 'technique');
