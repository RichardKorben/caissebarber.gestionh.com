-- BarberPay — base d'un barber — 0003 : écran de caisse (tickets en attente, verrouillage)

-- Tickets mis en attente (bouton pause). Ce ne sont PAS des ventes : pas de numéro, aucune valeur fiscale.
-- Ils sont gardés côté serveur pour être repris depuis n'importe quel poste du salon.
CREATE TABLE IF NOT EXISTS tickets_attente (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    libelle VARCHAR(60) NOT NULL,
    contenu JSON NOT NULL COMMENT 'Ticket tel que composé dans la caisse (lignes, remises, barbier)',
    total_ttc INT NOT NULL COMMENT 'Centimes, pour l''affichage de la liste',
    nb_articles SMALLINT UNSIGNED NOT NULL,
    barbier_id INT UNSIGNED NULL,
    cree_par INT UNSIGNED NULL,
    cree_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_tickets_attente_cree_le (cree_le)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Verrouillage automatique de la caisse après inactivité (0 = jamais).
ALTER TABLE parametres ADD COLUMN IF NOT EXISTS verrouillage_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 10 AFTER animation_poteau;
