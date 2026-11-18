<?php
/**
 * Scénario complet sur plusieurs jours, dans une base TEMPORAIRE (créée puis supprimée) avec l'horloge fixée :
 *   30/12/2024 : ouverture, ventes, remise, annulation, Z avec comptage
 *   31/12/2024 : une vente, pas de Z ; changement de régime de TVA demandé (différé)
 *   02/01/2025 : rattrapage automatique (Z du 31, clôture de décembre, clôture de 2024), régime appliqué
 */
declare(strict_types=1);

use BarberPay\Clotures;
use BarberPay\Database;
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
$sousDomaine = 'test-journee-' . $suffixe;
$base = 'BARBER_test_' . $suffixe;
$commune = Database::commune();

try {
    // ── Mise en place ────────────────────────────────────────────────
    Database::serveur()->query("CREATE DATABASE `$base` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $requete = $commune->prepare("INSERT INTO barberpay_comptes (sous_domaine, base, nom_salon, statut) VALUES (?, ?, 'Salon de test', 'actif')");
    $requete->bind_param('ss', $sousDomaine, $base);
    $requete->execute();
    $tenant = Tenant::trouver($commune, $sousDomaine);
    $con = Database::barber($base);
    (new Migrator($con, Migrator::DOSSIER_BARBER, Migrator::TABLE_BARBER))->migrer();
    $journal = new Journal($con, $tenant);
    $ventes = new Ventes($con, $tenant, $journal);
    $clotures = new Clotures($con, $tenant, $journal);
    $parametres = new Parametres($con, $tenant, $journal, 1);
    $parametres->charger();
    $con->query("UPDATE parametres SET regime_tva = 'assujetti', siret = '88123456700017' WHERE id = 1");
    $con->query("INSERT INTO utilisateurs (id, prenom, role) VALUES (1, 'Max', 'gerant'), (2, 'Yanis', 'barbier')");
    $con->query("INSERT INTO articles (id, type, nom, prix_ttc, taux_tva, stock) VALUES (1, 'service', 'Coupe', 2500, 2000, NULL), (2, 'produit', 'Cire', 1200, 2000, 10)");
    $l = static fn (int $id, string $type, int $prix, int $q = 1): array =>
        ['articleId' => $id, 'type' => $type, 'nom' => 'x', 'prix' => $prix, 'taux' => 2000, 'quantite' => $q, 'barbierId' => 2, 'remise' => null];
    $vendre = static fn (array $lignes, array $paiements, ?array $remise = null) =>
        $ventes->encaisser(['barbierId' => 2, 'remise' => $remise, 'lignes' => $lignes], $paiements, null, null, 2);

    // ── Jour 1 : 30/12/2024 ──────────────────────────────────────────
    section('Jour 1 — 30/12/2024');
    Horloge::fixer('2024-12-30 09:00:00');
    $clotures->ouvrir(10000, 1);
    ok($clotures->ouverture('2024-12-30')['fond'] === 10000, 'ouverture avec 100 € de fond');
    refuse(fn () => $clotures->ouvrir(5000, 1), 'déjà ouverte', 'deuxième ouverture refusée');
    Horloge::fixer('2024-12-30 10:00:00');
    $a = $vendre([$l(1, 'service', 2500), $l(2, 'produit', 1200)], [['moyen' => 'especes', 'montant' => 3700, 'recu' => 5000]]);
    Horloge::fixer('2024-12-30 11:00:00');
    $b = $vendre([$l(1, 'service', 2500)], [['moyen' => 'cb', 'montant' => 2250]], ['type' => 'pct', 'valeur' => 1000, 'motif' => 'Fidélité']);
    Horloge::fixer('2024-12-30 14:00:00');
    $c = $vendre([$l(1, 'service', 2500)], [['moyen' => 'cheque', 'montant' => 2500]]);
    Horloge::fixer('2024-12-30 15:00:00');
    $ventes->annuler($c['id'], 1, 'Erreur de saisie');
    ok($a['rendu'] === 1300 && $b['total'] === 2250, 'ventes : 37 € en espèces (13 € rendus), 22,50 € en carte après 10 %');
    ok($clotures->especesAttendues('2024-12-30') === 13700, 'espèces attendues : 100 € de fond + 37 €');

    Horloge::fixer('2024-12-30 19:00:00');
    $z = $clotures->cloturerJour('2024-12-30', 1, false, 13500);
    $detail = json_decode($z['detail'], true);
    ok($z['nb_ventes'] === 3 && $z['nb_annulations'] === 1, 'Z : 3 ventes, 1 annulation');
    ok($z['ventes_ttc'] === 8450 && $z['annulations_ttc'] === -2500 && $z['net_ttc'] === 5950, 'Z : ventes 84,50 €, annulations −25 €, net 59,50 €', $z);
    ok($z['net_ht'] === 4958 && $z['net_tva'] === 992, 'Z : HT 49,58 € + TVA 9,92 €', [$z['net_ht'], $z['net_tva']]);
    ok($detail['par_moyen'] === ['cb' => 2250, 'especes' => 3700, 'cheque' => 0], 'Z : par moyen (le chèque annulé s\'annule)', $detail['par_moyen']);
    ok($detail['par_type'] === ['service' => 4750, 'produit' => 1200], 'Z : services 47,50 € / produits 12 €', $detail['par_type']);
    ok($detail['remises'] === 250, 'Z : 2,50 € de remises');
    ok($z['especes_attendues'] === 13700 && $z['especes_comptees'] === 13500 && $z['ecart'] === -200, 'Z : écart de caisse −2 €');
    ok($z['grand_total'] === 5950 && $z['grand_total_absolu'] === 10950, 'Z : grand total perpétuel 59,50 € (109,50 € en valeur absolue)');
    ok($z['premier_ticket'] === 1 && $z['dernier_ticket'] === 4, 'Z : tickets n° 1 à 4');
    ok(Signature::pour($tenant)->verifier($z['hash'], $z['signature']), 'Z signé');

    Horloge::fixer('2024-12-30 19:30:00');
    refuse(fn () => $vendre([$l(1, 'service', 2500)], [['moyen' => 'cb', 'montant' => 2500]]), 'clôturée', 'vente après le Z refusée');
    refuse(fn () => $ventes->annuler($a['id'], 1, 'x'), 'clôturée', 'annulation après le Z refusée');
    refuse(fn () => $clotures->cloturerJour('2024-12-30', 1), 'déjà clôturée', 'deuxième Z refusé');
    refuse(fn () => $clotures->ouvrir(1000, 1), 'clôturée', 'ouverture après le Z refusée');
    $d1 = $ventes->duplicata($a['id'], 1);
    $d2 = $ventes->duplicata($a['id'], 1);
    ok($d1['numero'] === 1 && $d2['numero'] === 2, 'duplicatas numérotés 1 puis 2');

    // ── Jour 2 : 31/12/2024 ──────────────────────────────────────────
    section('Jour 2 — 31/12/2024 (pas de Z)');
    Horloge::fixer('2024-12-31 10:00:00');
    $vendre([$l(1, 'service', 2500)], [['moyen' => 'cb', 'montant' => 2500]]);
    Horloge::fixer('2024-12-31 11:00:00');
    $saisie = $parametres->charger();
    $saisie['regime_tva'] = Parametres::FRANCHISE;
    foreach (['seuil_services', 'seuil_services_majore', 'seuil_ventes', 'seuil_ventes_majore'] as $s) {
        $saisie[$s] = (string) ($saisie[$s] / 100);
    }
    $erreurs = $parametres->enregistrer(array_map(static fn ($v) => $v === null ? '' : (string) $v, $saisie), $commune);
    $p = $parametres->charger();
    ok(!$erreurs && $p['regime_tva'] === 'assujetti' && $p['regime_tva_a_venir'] === 'franchise', 'passage en franchise demandé : différé (des ventes aujourd\'hui)', $erreurs ?: $p['regime_tva']);

    // ── Jour 3 : 02/01/2025 ──────────────────────────────────────────
    section('Jour 3 — 02/01/2025 : rattrapage automatique');
    Horloge::fixer('2025-01-02 09:00:00');
    $faites = $clotures->rattraper();
    ok($faites === ['jour 2024-12-31', 'mois 2024-12', 'année 2024'], 'Z du 31/12, décembre et 2024 clôturés automatiquement', $faites);
    $liste = $clotures->lister();
    $mois = array_values(array_filter($liste, static fn ($x) => $x['type'] === 'mois'))[0];
    $annee = array_values(array_filter($liste, static fn ($x) => $x['type'] === 'annee'))[0];
    ok($mois['net_ttc'] === 8450 && $annee['net_ttc'] === 8450, 'décembre et 2024 : net 84,50 €');
    $z31 = array_values(array_filter($liste, static fn ($x) => $x['type'] === 'jour' && $x['periode'] === '2024-12-31'))[0];
    ok((bool) $z31['automatique'] && $z31['ecart'] === null, 'Z du 31 marqué automatique, sans comptage');
    ok($clotures->rattraper() === [], 'rien à rattraper une seconde fois');
    $p = $parametres->charger();
    ok($p['regime_tva'] === 'franchise' && $p['regime_tva_a_venir'] === null, 'régime de franchise appliqué après la clôture du 31');
    $v = $vendre([$l(1, 'service', 2500)], [['moyen' => 'cb', 'montant' => 2500]]);
    $t = $ventes->charger($v['id']);
    ok($t['regime_tva'] === 'franchise' && $t['total_tva'] === 0 && $t['tva'] === [], 'vente du 2 janvier en franchise (pas de TVA)');
    refuse(fn () => $clotures->cloturerMois('2025-01', 1), 'pas terminé', 'clôture d\'un mois en cours refusée');
    refuse(fn () => $con->query('UPDATE clotures SET net_ttc = 0'), 'BarberPay', 'clôture non modifiable (trigger)');
    refuse(fn () => $con->query('DELETE FROM duplicatas'), 'BarberPay', 'duplicatas non supprimables (trigger)');

    section('Intégrité');
    $integrite = new Integrite($con, $tenant);
    $r = $integrite->verifier();
    ok($r['ok'] && $r['clotures'] === 4, 'tout est intègre (' . $r['tickets'] . ' tickets, ' . $r['clotures'] . ' clôtures, ' . $r['evenements'] . ' événements)', $r['erreurs']);
    $codes = array_column($con->query('SELECT code FROM journal ORDER BY numero')->fetch_all(), 0);
    ok(in_array('OUVERTURE_CAISSE', $codes, true) && count(array_keys($codes, 'CLOTURE')) === 4 && in_array('DUPLICATA', $codes, true)
        && in_array('REGIME_TVA_MODIFIE', $codes, true) && in_array('REGIME_TVA_APPLIQUE', $codes, true), 'journal : ouverture, 4 clôtures, duplicatas, régime de TVA');
    $con->query('DROP TRIGGER clotures_sans_modification');
    $con->query("UPDATE clotures SET net_ttc = 1 WHERE type = 'jour' AND periode = '2024-12-30'");
    $r = $integrite->verifier();
    ok(!$r['ok'] && str_contains(implode(' ', $r['erreurs']), 'Clôture n° 1'), 'Z falsifié (trigger supprimé) : détecté', $r['erreurs']);
} finally {
    Horloge::fixer(null);
    Database::serveur()->query("DROP DATABASE IF EXISTS `$base`");
    $requete = $commune->prepare('DELETE FROM barberpay_comptes WHERE sous_domaine = ?');
    $requete->bind_param('s', $sousDomaine);
    $requete->execute();
    @unlink(Signature::DOSSIER . '/' . $sousDomaine . '.cle');
}
fin();
