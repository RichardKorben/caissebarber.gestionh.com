<?php
/**
 * Tests du module fiscal, directement sur la base du compte de test « barbertest » (BARBER_dev2, sans adresse web).
 * Écrit de vrais tickets dans cette base (en ajout seul, c'est voulu) ; ne touche jamais une autre base.
 */
declare(strict_types=1);

use BarberPay\Database;
use BarberPay\Integrite;
use BarberPay\Journal;
use BarberPay\Parametres;
use BarberPay\Tenant;
use BarberPay\Ventes;

require __DIR__ . '/lib.php';

$tenant = Tenant::trouver(Database::commune(), 'barbertest');
if ($tenant === null || $tenant->base !== 'BARBER_dev2') {
    fwrite(STDERR, "Compte de test « barbertest » (BARBER_dev2) introuvable.\n");
    exit(2);
}
$con = Database::barber($tenant->base);
$journal = new Journal($con, $tenant);
$ventes = new Ventes($con, $tenant, $journal);
(new Parametres($con, $tenant))->charger();
// Salon de test assujetti à la TVA : les tickets ont des lignes de TVA (sinon le test des triggers de ticket_tva ne toucherait aucune ligne).
$con->query("UPDATE parametres SET regime_tva = 'assujetti' WHERE id = 1");

// Un service et un produit de test (créés une fois).
$con->query("INSERT INTO articles (type, nom, prix_ttc, taux_tva, stock, ordre)
             SELECT 'service', 'Coupe test', 2500, 2000, NULL, 1 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM articles WHERE nom = 'Coupe test')");
$con->query("INSERT INTO articles (type, nom, prix_ttc, taux_tva, stock, ordre)
             SELECT 'produit', 'Cire test', 1200, 2000, 100, 2 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM articles WHERE nom = 'Cire test')");
$coupe = (int) $con->query("SELECT id FROM articles WHERE nom = 'Coupe test'")->fetch_row()[0];
$cire = (int) $con->query("SELECT id FROM articles WHERE nom = 'Cire test'")->fetch_row()[0];
$stock = static fn (): int => (int) $con->query("SELECT stock FROM articles WHERE id = $cire")->fetch_row()[0];
$ligne = static fn (int $id, string $type, int $prix, int $q = 1, ?array $remise = null): array =>
    ['articleId' => $id, 'type' => $type, 'nom' => 'peu importe', 'prix' => $prix, 'taux' => 2000, 'quantite' => $q, 'barbierId' => null, 'remise' => $remise];

section('Encaissement');
$stockAvant = $stock();
$avant = (int) $con->query("SELECT valeur FROM compteurs WHERE nom = 'ticket'")->fetch_row()[0];
$v = $ventes->encaisser(['lignes' => [$ligne($coupe, 'service', 2500), $ligne($cire, 'produit', 1200, 2)]], [['moyen' => 'cb', 'montant' => 4900]], 4900, null, 0);
ok($v['numero'] === $avant + 1 && $v['total'] === 4900, 'ticket numéroté à la suite (n° ' . $v['numero'] . '), 49 €');
ok($stock() === $stockAvant - 2, 'stock du produit : −2');
$t = $ventes->charger($v['id']);
ok($t['hash_precedent'] !== '' || $avant === 0, 'chaîné au ticket précédent');
ok(count($t['lignes']) === 2 && $t['lignes'][1]['libelle'] === 'Cire test', 'libellé repris du catalogue (pas du navigateur)');
$v2 = $ventes->encaisser(['lignes' => [$ligne($coupe, 'service', 1)]], [['moyen' => 'cb', 'montant' => 2500]], null, null, 0);
ok($v2['total'] === 2500, 'prix trafiqué (1 centime) : le serveur reprend 25 €');
refuse(fn () => $ventes->encaisser(['lignes' => [$ligne($coupe, 'service', 2500)]], [['moyen' => 'cb', 'montant' => 2400]], 2400, null, 0), 'Les prix ont changé', 'total affiché différent → conflit');
$cle = sprintf('%08x-0000-4000-8000-%012x', random_int(0, 0xffffffff), random_int(0, 0xffffffffffff));
$a = $ventes->encaisser(['lignes' => [$ligne($coupe, 'service', 2500)]], [['moyen' => 'especes', 'montant' => 2500, 'recu' => 3000]], 2500, $cle, 0);
$b = $ventes->encaisser(['lignes' => [$ligne($coupe, 'service', 2500)]], [['moyen' => 'especes', 'montant' => 2500, 'recu' => 3000]], 2500, $cle, 0);
ok($a['id'] === $b['id'] && $b['deja'] && $a['rendu'] === 500, 'même clé envoyée deux fois : un seul ticket, 5 € rendus');

section('Annulation');
$stockAvant = $stock();
$annul = $ventes->annuler($v['id'], 0, 'Erreur de saisie');
$ta = $ventes->charger($annul['id']);
ok($ta['type'] === 'annulation' && $ta['total_ttc'] === -4900 && $ta['lignes'][1]['quantite'] === -2, 'ticket d\'annulation négatif');
ok($ta['paiements'][0]['moyen'] === 'cb' && $ta['paiements'][0]['montant'] === -4900, 'remboursement par le même moyen');
ok($stock() === $stockAvant + 2, 'stock rendu');
ok($ventes->charger($v['id'])['annule_par']['id'] === $annul['id'], 'le ticket d\'origine indique son annulation');
refuse(fn () => $ventes->annuler($v['id'], 0, 'encore'), 'déjà été annulé', 'deux annulations refusées');
refuse(fn () => $ventes->annuler($annul['id'], 0, 'x'), 'ne peut pas être annulé', 'annuler une annulation refusé');
refuse(fn () => $ventes->annuler($v2['id'], 0, ''), 'motif', 'motif obligatoire');
$evenement = $con->query("SELECT code, details FROM journal ORDER BY numero DESC LIMIT 1")->fetch_assoc();
ok($evenement['code'] === 'ANNULATION' && str_contains($evenement['details'], 'Erreur de saisie'), 'annulation inscrite au journal');

section('Inaltérabilité');
ok((int) $con->query('SELECT COUNT(*) FROM ticket_tva')->fetch_row()[0] > 0, 'il y a des lignes de TVA à protéger');
foreach ([
    'UPDATE tickets SET total_ttc = 1',
    'DELETE FROM tickets ORDER BY id DESC LIMIT 1',
    'UPDATE ticket_lignes SET prix_unitaire = 1',
    'DELETE FROM ticket_paiements',
    'UPDATE ticket_tva SET tva = 0',
    'UPDATE journal SET code = \'X\'',
    'DELETE FROM journal',
    'DELETE FROM mouvements_stock',
] as $sql) {
    refuse(fn () => $con->query($sql), 'BarberPay', 'refusé : ' . $sql);
}

section('Contrôle d\'intégrité');
$integrite = new Integrite($con, $tenant);
$r = $integrite->verifier();
ok($r['ok'], 'base intègre (' . $r['tickets'] . ' tickets, ' . $r['evenements'] . ' événements)', $r['erreurs']);
// Falsification par un administrateur de la base : trigger supprimé, montant modifié, puis tout est remis.
$con->query('DROP TRIGGER tickets_sans_modification');
try {
    $con->query("UPDATE tickets SET total_ttc = 100 WHERE id = {$v2['id']}");
    $r = $integrite->verifier();
    ok(!$r['ok'] && str_contains(implode(' ', $r['erreurs']), 'contenu modifié'), 'falsification détectée', $r['erreurs']);
    $con->query("UPDATE tickets SET total_ttc = 2500 WHERE id = {$v2['id']}");
} finally {
    $con->query("CREATE TRIGGER tickets_sans_modification BEFORE UPDATE ON tickets FOR EACH ROW
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'BarberPay : un ticket enregistré ne peut pas être modifié (faire une annulation).'");
}
ok($integrite->verifier()['ok'], 'valeur remise : de nouveau intègre');
refuse(fn () => $con->query('UPDATE tickets SET total_ttc = 1'), 'BarberPay', 'trigger bien remis en place');

fin();
