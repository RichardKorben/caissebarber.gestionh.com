-- BarberPay — base d'un barber — 0006 : archives fiscales signées (une par clôture mensuelle ou annuelle)

CREATE TABLE IF NOT EXISTS archives (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    cloture_id INT UNSIGNED NOT NULL,
    type ENUM('mois', 'annee') NOT NULL,
    periode VARCHAR(10) NOT NULL,
    fichier VARCHAR(190) NOT NULL COMMENT 'Nom du fichier ZIP dans /var/kdSite/barberpay/archives/<sous-domaine>/',
    taille INT UNSIGNED NOT NULL,
    empreinte CHAR(64) NOT NULL COMMENT 'SHA-256 du fichier ZIP',
    signature VARCHAR(100) NOT NULL COMMENT 'Ed25519 de l''empreinte',
    operateur_id INT UNSIGNED NULL,
    cree_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_archives_cloture (cloture_id),
    CONSTRAINT fk_archives_cloture FOREIGN KEY (cloture_id) REFERENCES clotures (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TRIGGER IF NOT EXISTS archives_sans_modification BEFORE UPDATE ON archives FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'BarberPay : une archive ne peut pas être modifiée.';
CREATE TRIGGER IF NOT EXISTS archives_sans_suppression BEFORE DELETE ON archives FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'BarberPay : une archive ne peut pas être supprimée.';
