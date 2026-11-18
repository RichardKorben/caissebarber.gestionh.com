-- BarberPay — base commune GH_main — 0003 : compte de démonstration (https://barberdemo.gestionh.com)
-- demo = 1 : connexion automatique, bandeau « démo », actions sensibles bloquées, base recréée chaque nuit
-- (barberpay demo:reinitialiser). Un seul compte de démo : barberdemo.
ALTER TABLE barberpay_comptes ADD COLUMN IF NOT EXISTS demo TINYINT(1) NOT NULL DEFAULT 0 AFTER statut;
