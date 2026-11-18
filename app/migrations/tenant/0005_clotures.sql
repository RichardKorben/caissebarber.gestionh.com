-- BarberPay — base d'un barber — 0005 : caisse du jour, clôtures (Z, mois, année), duplicatas

INSERT IGNORE INTO compteurs (nom) VALUES ('cloture');

-- Changement de régime de TVA demandé en cours de journée : appliqué à la clôture du jour.
ALTER TABLE parametres ADD COLUMN IF NOT EXISTS regime_tva_a_venir ENUM('franchise', 'assujetti') NULL AFTER regime_tva;

-- Ouverture de la caisse : fond de caisse du jour (une par jour).
CREATE TABLE IF NOT EXISTS caisse_ouvertures (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    jour DATE NOT NULL,
    fond INT NOT NULL COMMENT 'Centimes',
    operateur_id INT UNSIGNED NULL,
    ouverte_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_caisse_ouvertures_jour (jour)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Clôtures : journalière (Z), mensuelle, annuelle. Chaînées et signées comme les tickets.
CREATE TABLE IF NOT EXISTS clotures (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    numero BIGINT UNSIGNED NOT NULL COMMENT 'Continu, toutes clôtures confondues',
    type ENUM('jour', 'mois', 'annee') NOT NULL,
    periode VARCHAR(10) NOT NULL COMMENT '2026-11-18, 2026-11 ou 2026',
    date_cloture DATETIME NOT NULL,
    automatique TINYINT(1) NOT NULL DEFAULT 0,
    operateur_id INT UNSIGNED NULL,
    regime_tva ENUM('franchise', 'assujetti') NOT NULL,
    premier_ticket BIGINT UNSIGNED NULL,
    dernier_ticket BIGINT UNSIGNED NULL,
    nb_ventes INT NOT NULL,
    nb_annulations INT NOT NULL,
    ventes_ttc INT NOT NULL,
    annulations_ttc INT NOT NULL,
    net_ttc INT NOT NULL,
    net_ht INT NOT NULL,
    net_tva INT NOT NULL,
    grand_total INT NOT NULL COMMENT 'Grand total perpétuel : net cumulé depuis le premier ticket',
    grand_total_absolu BIGINT NOT NULL COMMENT 'Ventes + valeur absolue des annulations, cumulées depuis le début',
    fond INT NULL COMMENT 'Z : fond de caisse',
    especes_attendues INT NULL,
    especes_comptees INT NULL,
    ecart INT NULL,
    detail TEXT NOT NULL COMMENT 'JSON : par taux, par moyen, par barbier, par type, remises',
    version_logiciel VARCHAR(20) NOT NULL,
    hash_precedent CHAR(64) NOT NULL,
    hash CHAR(64) NOT NULL,
    signature VARCHAR(100) NOT NULL,
    UNIQUE KEY uq_clotures_numero (numero),
    UNIQUE KEY uq_clotures_periode (type, periode)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Duplicatas : chaque réimpression d'un ticket est numérotée et conservée.
CREATE TABLE IF NOT EXISTS duplicatas (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT UNSIGNED NOT NULL,
    numero SMALLINT UNSIGNED NOT NULL COMMENT '1er duplicata, 2e…',
    operateur_id INT UNSIGNED NULL,
    cree_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_duplicatas_ticket_numero (ticket_id, numero),
    CONSTRAINT fk_duplicatas_ticket FOREIGN KEY (ticket_id) REFERENCES tickets (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TRIGGER IF NOT EXISTS clotures_sans_modification BEFORE UPDATE ON clotures FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'BarberPay : une clôture ne peut pas être modifiée.';
CREATE TRIGGER IF NOT EXISTS clotures_sans_suppression BEFORE DELETE ON clotures FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'BarberPay : une clôture ne peut pas être supprimée.';
CREATE TRIGGER IF NOT EXISTS caisse_ouvertures_sans_modification BEFORE UPDATE ON caisse_ouvertures FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'BarberPay : table en ajout seul (caisse_ouvertures).';
CREATE TRIGGER IF NOT EXISTS caisse_ouvertures_sans_suppression BEFORE DELETE ON caisse_ouvertures FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'BarberPay : table en ajout seul (caisse_ouvertures).';
CREATE TRIGGER IF NOT EXISTS duplicatas_sans_modification BEFORE UPDATE ON duplicatas FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'BarberPay : table en ajout seul (duplicatas).';
CREATE TRIGGER IF NOT EXISTS duplicatas_sans_suppression BEFORE DELETE ON duplicatas FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'BarberPay : table en ajout seul (duplicatas).';
