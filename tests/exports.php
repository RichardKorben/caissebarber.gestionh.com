<?php
/**
 * Exports, archives et mode formation, dans une base TEMPORAIRE avec l'horloge fixée (novembre → décembre 2024).
 */
declare(strict_types=1);

use BarberPay\Archives;
use BarberPay\Clotures;
use BarberPay\Database;
use BarberPay\Exports;
use BarberPay\Horloge;
use BarberPay\Integrite;
use BarberPay\Journal;
use BarberPay\Migrator;
use BarberPay\Parametres;
use BarberPay\Signature;
use BarberPay\Tenant;
use BarberPay\Ventes;

require __DIR__ . '/lib.php';

$suffixe = substr(bin2hex(random_bytes(4)), 0, 6);
$sousDomaine = 'test-exports-' . $suffixe;
$base = 'BARBER_test_' . $suffixe;
$commune = Database::commune();

try {
    Database::serveur()->query("CREATE DATABASE `$base` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $requete = $commune->prepare("INSERT INTO barberpay_comptes (sous_domaine, base, nom_salon, statut) VALUES (?, ?, 'Salon export', 'actif')");
    $requete->bind_param('ss', $sousDomaine, $base);
    $requete->execute();
    $tenant = Tenant::trouver($commune, $sousDomaine);
    $con = Database::barber($base);
    (new Migrator($con, Migrator::DOSSIER_BARBER, Migrator::TABLE_BARBER))->migrer();
    $journal = new Journal($con, $tenant);
    $ventes = new Ventes($con, $tenant, $journal);
    $clotures = new Clotures($con, $tenant, $journal);
    $exports = new Exports($con, $tenant);
    (new Parametres($con, $tenant))->charger();
    $con->query("UPDATE parametres SET regime_tva = 'assujetti' WHERE id = 1");
    $con->query("INSERT INTO utilisateurs (id, prenom, role) VALUES (1, 'Max', 'gerant')");
    $con->query("INSERT INTO articles (id, type, nom, prix_ttc, taux_tva, stock) VALUES (1, 'service', 'Coupe', 2500, 2000, NULL), (2, 'produit', 'Cire', 1200, 2000, 10)");
    $l = static fn (int $id, string $type, int $prix, int $q = 1): array =>
        ['articleId' => $id, 'type' => $type, 'nom' => 'x', 'prix' => $prix, 'taux' => 2000, 'quantite' => $q, 'barbierId' => 1, 'remise' => null];

    Horloge::fixer('2024-11-05 10:00:00');
    $a = $ventes->encaisser(['lignes' => [$l(1, 'service', 2500), $l(2, 'produit', 1200, 2)]], [['moyen' => 'cb', 'montant' => 4900]], null, null, 1);
    Horloge::fixer('2024-11-20 10:00:00');
    $b = $ventes->encaisser(['lignes' => [$l(1, 'service', 2500)]], [['moyen' => 'especes', 'montant' => 2500, 'recu' => 3000]], null, null, 1);
    Horloge::fixer('2024-11-20 11:00:00');
    $ventes->annuler($b['id'], 1, 'Erreur');

    section('Mode formation');
    $stock = static fn (): int => (int) $con->query('SELECT stock FROM articles WHERE id = 2')->fetch_row()[0];
    $avant = $stock();
    $f = $ventes->encaisser(['lignes' => [$l(2, 'produit', 1200)]], [['moyen' => 'cb', 'montant' => 1200]], null, null, 1, true);
    $tf = $ventes->charger($f['id']);
    ok($tf['chaine'] === 'formation' && $tf['numero'] === 1, 'ticket de formation : chaîne à part, numéro 1');
    ok($stock() === $avant, 'le mode formation ne touche pas au stock');
    $totaux = $clotures->totaux('2024-11-01', '2024-12-01');
    ok($totaux['nb_ventes'] === 2 && $totaux['net_ttc'] === 4900, 'les totaux ignorent la formation (2 ventes réelles, net 49 €)', $totaux['net_ttc']);
    refuse(fn () => $ventes->annuler($f['id'], 1, 'x'), 'introuvable', 'un ticket de formation ne s\'annule pas');

    section('Livre des recettes');
    $livre = $exports->livreRecettes('2024-11-01', '2024-12-01');
    ok(count($livre) === 3, '3 pièces (2 ventes + 1 annulation), sans la formation', count($livre));
    ok($livre[0]['date'] === '05/11/2024' && $livre[0]['nature'] === 'Services et produits' && $livre[0]['montant'] === 4900, '1re ligne : 05/11, services et produits, 49 €');
    ok($livre[0]['detail'] === 'Coupe, 2 × Cire' && $livre[0]['reglement'] === 'Carte bancaire 49,00', 'détail et règlement', $livre[0]);
    ok($livre[2]['nature'] === 'Annulation du ticket n° 000002' && $livre[2]['montant'] === -2500, 'annulation en négatif');
    ok(array_sum(array_column($livre, 'montant')) === 4900, 'total du livre = net de la période');

    section('URSSAF');
    $u = $exports->urssaf(2024);
    ok($u['mois']['2024-11'] === ['services' => 2500, 'produits' => 2400, 'total' => 4900], 'novembre : 25 € de services, 24 € de produits', $u['mois']['2024-11']);
    ok($u['trimestres'][4]['total'] === 4900 && $u['annee']['total'] === 4900 && $u['mois']['2024-10']['total'] === 0, 'trimestre et année cohérents');

    section('Journal des ventes et e-reporting');
    $jv = $exports->journalVentes('2024-11-01', '2024-12-01');
    $tva = array_sum(array_map(static fn ($x) => $x['nature'] === 'Ventes' ? $x['ttc'] : 0, $jv));
    $reglements = array_sum(array_map(static fn ($x) => $x['nature'] === 'Règlements' ? $x['ttc'] : 0, $jv));
    ok($tva === 4900 && $reglements === 4900, 'journal des ventes : TVA et règlements = 49 €');
    $er = $exports->eReporting('2024-11-01', '2024-12-01');
    ok(array_sum(array_column($er, 'ttc')) === 4900 && $er[0]['date'] === '2024-11-05' && $er[0]['ttc'] === 4900 && $er[0]['tva'] + $er[0]['base_ht'] === 4900, 'e-reporting : totaux journaliers par taux', $er);

    section('Export fiscal et archive');
    $zipChemin = $exports->zipFiscal('2024-11-01', '2024-12-01', 'Test');
    $zip = new ZipArchive();
    $zip->open($zipChemin);
    $noms = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $noms[] = $zip->getNameIndex($i);
    }
    ok(count(array_intersect(['tickets.csv', 'ticket_lignes.csv', 'clotures.csv', 'journal.csv', 'cle_publique_ed25519.txt', 'LISEZMOI.txt', 'integrite.txt'], $noms)) === 7, 'ZIP : CSV, clé publique, notice, intégrité', $noms);
    $csvTickets = array_filter(explode("\n", (string) $zip->getFromName('tickets.csv')));
    ok(count($csvTickets) === 1 + 4, 'tickets.csv : en-tête + 4 tickets (3 réels + 1 formation, marqué)', count($csvTickets));
    ok(str_starts_with((string) $zip->getFromName('integrite.txt'), 'INTÈGRE'), 'intégrité jointe : INTÈGRE');
    ok(trim((string) $zip->getFromName('cle_publique_ed25519.txt')) === Signature::pour($tenant)->clePublique(), 'clé publique jointe');
    $zip->close();
    unlink($zipChemin);

    Horloge::fixer('2024-12-02 09:00:00');
    $faites = $clotures->rattraper();
    ok(in_array('mois 2024-11', $faites, true), 'novembre clôturé automatiquement', $faites);
    $mois = array_values(array_filter($clotures->lister(), static fn ($c) => $c['type'] === 'mois'))[0];
    $archives = new Archives($con, $tenant, $journal);
    $archive = $archives->pour((int) $mois['id'], 1);
    ok($archive['type'] === 'mois' && $archive['periode'] === '2024-11' && $archives->verifier($archive), 'archive de novembre créée, empreinte et signature valides');
    ok($archives->pour((int) $mois['id'], 1)['id'] === $archive['id'], 'deuxième demande : la même archive (pas recréée)');
    refuse(fn () => $con->query('DELETE FROM archives'), 'BarberPay', 'archive non supprimable (trigger)');
    ok((new Integrite($con, $tenant))->verifier()['ok'], 'base intègre avec l\'archive');

    $chemin = Archives::DOSSIER . '/' . $sousDomaine . '/' . $archive['fichier'];
    file_put_contents($chemin, 'falsifié', FILE_APPEND);
    $r = (new Integrite($con, $tenant))->verifier();
    ok(!$r['ok'] && str_contains(implode(' ', $r['erreurs']), 'Archive mois-2024-11.zip'), 'fichier d\'archive modifié : détecté', $r['erreurs']);
    refuse(fn () => $archives->fichierVerifie($archive), 'téléchargement refusé', 'téléchargement d\'une archive modifiée refusé');
} finally {
    Horloge::fixer(null);
    Database::serveur()->query("DROP DATABASE IF EXISTS `$base`");
    $requete = $commune->prepare('DELETE FROM barberpay_comptes WHERE sous_domaine = ?');
    $requete->bind_param('s', $sousDomaine);
    $requete->execute();
    @unlink(Signature::DOSSIER . '/' . $sousDomaine . '.cle');
    foreach (glob(Archives::DOSSIER . '/' . $sousDomaine . '/*') ?: [] as $fichier) {
        @unlink($fichier);
    }
    @rmdir(Archives::DOSSIER . '/' . $sousDomaine);
}
fin();
