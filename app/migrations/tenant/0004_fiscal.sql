-- BarberPay — base d'un barber — 0004 : module fiscal (art. 286 I 3° bis du CGI)
--
-- Tickets, lignes, TVA, paiements, mouvements de stock et journal des événements sont en AJOUT SEUL :
-- des triggers refusent toute modification ou suppression. Une erreur se corrige par un ticket d'annulation.
-- Chaque ticket et chaque événement est chaîné (empreinte SHA-256 incluant l'empreinte précédente) et signé
-- (Ed25519, clé propre au barber, rangée hors de la base) : une modification faite malgré les triggers
-- (par un administrateur de la base) est détectée par le contrôle d'intégrité.

-- Compteurs : numéro et dernière empreinte de chaque chaîne. La ligne est verrouillée (SELECT … FOR UPDATE)
-- pendant l'enregistrement d'un ticket : deux encaissements simultanés ne peuvent pas avoir le même numéro.
CREATE TABLE IF NOT EXISTS compteurs (
    nom VARCHAR(30) NOT NULL PRIMARY KEY,
    valeur BIGINT UNSIGNED NOT NULL DEFAULT 0,
    dernier_hash CHAR(64) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO compteurs (nom) VALUES ('ticket'), ('ticket_formation'), ('journal');

CREATE TABLE IF NOT EXISTS tickets (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    chaine ENUM('reel', 'formation') NOT NULL DEFAULT 'reel',
    numero BIGINT UNSIGNED NOT NULL COMMENT 'Continu, sans trou, par chaîne',
    type ENUM('vente', 'annulation') NOT NULL,
    ticket_origine_id INT UNSIGNED NULL COMMENT 'Annulation : ticket annulé',
    date_ticket DATETIME NOT NULL,
    operateur_id INT UNSIGNED NULL,
    barbier_id INT UNSIGNED NULL,
    regime_tva ENUM('franchise', 'assujetti') NOT NULL,
    nb_articles INT NOT NULL,
    sous_total INT NOT NULL COMMENT 'Centimes, après remises de ligne',
    remise_ticket INT NOT NULL DEFAULT 0,
    remise_type ENUM('pct', 'eur') NULL,
    remise_valeur INT NULL,
    remise_motif VARCHAR(60) NULL,
    total_ttc INT NOT NULL COMMENT 'Négatif pour une annulation',
    total_ht INT NOT NULL,
    total_tva INT NOT NULL,
    version_logiciel VARCHAR(20) NOT NULL,
    entete TEXT NOT NULL COMMENT 'JSON : identité du salon au moment du ticket (nom, adresse, SIRET…), signée avec le ticket',
    jeton_public CHAR(32) NOT NULL COMMENT 'Lien du ticket en ligne (QR code)',
    cle_idempotence CHAR(36) NULL COMMENT 'Évite un double encaissement si la requête est renvoyée',
    hash_precedent CHAR(64) NOT NULL,
    hash CHAR(64) NOT NULL,
    signature VARCHAR(100) NOT NULL,
    cree_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tickets_chaine_numero (chaine, numero),
    UNIQUE KEY uq_tickets_jeton (jeton_public),
    UNIQUE KEY uq_tickets_idempotence (cle_idempotence),
    UNIQUE KEY uq_tickets_origine (ticket_origine_id) COMMENT 'Un ticket ne peut être annulé qu''une fois',
    KEY ix_tickets_date (date_ticket),
    CONSTRAINT fk_tickets_origine FOREIGN KEY (ticket_origine_id) REFERENCES tickets (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ticket_lignes (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT UNSIGNED NOT NULL,
    rang SMALLINT UNSIGNED NOT NULL,
    article_id INT UNSIGNED NULL COMMENT 'NULL = montant libre',
    type ENUM('service', 'produit') NOT NULL,
    libelle VARCHAR(80) NOT NULL COMMENT 'Copié au moment de la vente',
    quantite INT NOT NULL COMMENT 'Négative pour une annulation',
    prix_unitaire INT NOT NULL COMMENT 'Copié au moment de la vente',
    remise_ligne INT NOT NULL DEFAULT 0,
    remise_type ENUM('pct', 'eur') NULL,
    remise_valeur INT NULL,
    remise_motif VARCHAR(60) NULL,
    part_remise_ticket INT NOT NULL DEFAULT 0,
    total_ttc INT NOT NULL,
    taux_tva SMALLINT UNSIGNED NOT NULL,
    barbier_id INT UNSIGNED NULL,
    UNIQUE KEY uq_ticket_lignes_rang (ticket_id, rang),
    CONSTRAINT fk_ticket_lignes_ticket FOREIGN KEY (ticket_id) REFERENCES tickets (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ticket_tva (
    ticket_id INT UNSIGNED NOT NULL,
    taux SMALLINT UNSIGNED NOT NULL,
    base_ht INT NOT NULL,
    tva INT NOT NULL,
    ttc INT NOT NULL,
    PRIMARY KEY (ticket_id, taux),
    CONSTRAINT fk_ticket_tva_ticket FOREIGN KEY (ticket_id) REFERENCES tickets (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ticket_paiements (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT UNSIGNED NOT NULL,
    rang SMALLINT UNSIGNED NOT NULL,
    moyen ENUM('cb', 'especes', 'cheque', 'autre') NOT NULL,
    montant INT NOT NULL COMMENT 'Part du total réglée par ce moyen (négative pour un remboursement)',
    recu INT NULL COMMENT 'Espèces : somme donnée par le client',
    rendu INT NOT NULL DEFAULT 0 COMMENT 'Espèces : monnaie rendue',
    UNIQUE KEY uq_ticket_paiements_rang (ticket_id, rang),
    CONSTRAINT fk_ticket_paiements_ticket FOREIGN KEY (ticket_id) REFERENCES tickets (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mouvements_stock (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    article_id INT UNSIGNED NOT NULL,
    quantite INT NOT NULL COMMENT 'Négative = sortie',
    motif ENUM('vente', 'annulation', 'reception', 'correction') NOT NULL,
    ticket_id INT UNSIGNED NULL,
    operateur_id INT UNSIGNED NULL,
    cree_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_mouvements_stock_article (article_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Journal des événements (JET) : connexions, annulations, changements de prix et de paramètres fiscaux…
CREATE TABLE IF NOT EXISTS journal (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    numero BIGINT UNSIGNED NOT NULL,
    date_evenement DATETIME NOT NULL,
    code VARCHAR(40) NOT NULL,
    operateur_id INT UNSIGNED NULL,
    ticket_id INT UNSIGNED NULL,
    details TEXT NOT NULL COMMENT 'JSON, conservé tel qu''il a été signé',
    version_logiciel VARCHAR(20) NOT NULL,
    hash_precedent CHAR(64) NOT NULL,
    hash CHAR(64) NOT NULL,
    signature VARCHAR(100) NOT NULL,
    UNIQUE KEY uq_journal_numero (numero),
    KEY ix_journal_date (date_evenement),
    KEY ix_journal_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Inaltérabilité : aucune modification ni suppression ────────────────
CREATE TRIGGER IF NOT EXISTS tickets_sans_modification BEFORE UPDATE ON tickets FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'BarberPay : un ticket enregistré ne peut pas être modifié (faire une annulation).';
CREATE TRIGGER IF NOT EXISTS tickets_sans_suppression BEFORE DELETE ON tickets FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'BarberPay : un ticket enregistré ne peut pas être supprimé (faire une annulation).';
CREATE TRIGGER IF NOT EXISTS ticket_lignes_sans_modification BEFORE UPDATE ON ticket_lignes FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'BarberPay : table en ajout seul (ticket_lignes).';
CREATE TRIGGER IF NOT EXISTS ticket_lignes_sans_suppression BEFORE DELETE ON ticket_lignes FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'BarberPay : table en ajout seul (ticket_lignes).';
CREATE TRIGGER IF NOT EXISTS ticket_tva_sans_modification BEFORE UPDATE ON ticket_tva FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'BarberPay : table en ajout seul (ticket_tva).';
CREATE TRIGGER IF NOT EXISTS ticket_tva_sans_suppression BEFORE DELETE ON ticket_tva FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'BarberPay : table en ajout seul (ticket_tva).';
CREATE TRIGGER IF NOT EXISTS ticket_paiements_sans_modification BEFORE UPDATE ON ticket_paiements FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'BarberPay : table en ajout seul (ticket_paiements).';
CREATE TRIGGER IF NOT EXISTS ticket_paiements_sans_suppression BEFORE DELETE ON ticket_paiements FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'BarberPay : table en ajout seul (ticket_paiements).';
CREATE TRIGGER IF NOT EXISTS mouvements_stock_sans_modification BEFORE UPDATE ON mouvements_stock FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'BarberPay : table en ajout seul (mouvements_stock).';
CREATE TRIGGER IF NOT EXISTS mouvements_stock_sans_suppression BEFORE DELETE ON mouvements_stock FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'BarberPay : table en ajout seul (mouvements_stock).';
CREATE TRIGGER IF NOT EXISTS journal_sans_modification BEFORE UPDATE ON journal FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'BarberPay : le journal des événements ne peut pas être modifié.';
CREATE TRIGGER IF NOT EXISTS journal_sans_suppression BEFORE DELETE ON journal FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'BarberPay : le journal des événements ne peut pas être supprimé.';
