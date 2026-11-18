<?php
/** Tests unitaires (sans base de données). */
declare(strict_types=1);

use BarberPay\Calcul;
use BarberPay\Chaine;
use BarberPay\Montant;
use BarberPay\Parametres;
use BarberPay\Tenant;
use BarberPay\Ventes;

require __DIR__ . '/lib.php';

section('Montants');
ok(Montant::analyser('25') === 2500, '« 25 » → 2500');
ok(Montant::analyser('25,5') === 2550, '« 25,5 » → 2550');
ok(Montant::analyser('1 250,00 €') === 125000, '« 1 250,00 € » → 125000');
ok(Montant::analyser('-5') === null && Montant::analyser('douze') === null && Montant::analyser('1,234') === null, 'saisies invalides refusées');
ok(Montant::format(6300) === "63,00\u{00A0}€", 'format 63,00 €');
ok(Montant::formatCourt(2500) === "25\u{00A0}€" && Montant::formatCourt(2550) === "25,50\u{00A0}€", 'format court');

section('Calcul d\'un ticket');
$t = Calcul::ticket([['prix' => 3800, 'quantite' => 1, 'taux' => 2000], ['prix' => 2200, 'quantite' => 1, 'taux' => 2000]]);
ok($t['total'] === 6000 && $t['total_ht'] === 5000 && $t['total_tva'] === 1000, 'maquette : 60 € = 50 HT + 10 TVA');
$t = Calcul::ticket(array_fill(0, 3, ['prix' => 1000, 'quantite' => 1, 'taux' => 2000]), ['type' => 'eur', 'valeur' => 100]);
ok(array_column($t['lignes'], 'part_remise_ticket') === [34, 33, 33], '1 € réparti sur 3 lignes : 34/33/33 centimes');
$t = Calcul::ticket([['prix' => 2500, 'quantite' => 2, 'taux' => 2000, 'remise' => ['type' => 'pct', 'valeur' => 10000]]]);
ok($t['total'] === 0, 'ligne offerte → 0 €');
$t = Calcul::ticket([['prix' => 1000, 'quantite' => 1, 'taux' => 2000], ['prix' => 1000, 'quantite' => 1, 'taux' => 550]]);
ok(count($t['tva']) === 2 && $t['tva'][0]['taux'] === 2000 && $t['tva'][1]['ht'] === 948, 'deux taux : 5,5 % de 10 € → 9,48 HT');
refuse(fn () => Calcul::ticket([['prix' => 9999999, 'quantite' => 2, 'taux' => 2000]]), 'trop élevé', 'plafond 99 999,99 €');
refuse(fn () => Calcul::ticket([['prix' => '25', 'quantite' => 1, 'taux' => 2000]]), 'hors limites', 'prix non entier refusé');

section('Paiements');
ok(count(Ventes::validerPaiements([['moyen' => 'cb', 'montant' => 6000]], 6000)) === 1, 'CB du montant exact');
$p = Ventes::validerPaiements([['moyen' => 'especes', 'montant' => 6000, 'recu' => 10000]], 6000);
ok($p[0]['rendu'] === 4000, 'espèces : 100 € reçus → 40 € rendus');
refuse(fn () => Ventes::validerPaiements([['moyen' => 'cb', 'montant' => 5000]], 6000), 'ne correspondent pas', 'paiements ≠ total');
refuse(fn () => Ventes::validerPaiements([['moyen' => 'bitcoin', 'montant' => 6000]], 6000), 'inconnu', 'moyen inconnu');
refuse(fn () => Ventes::validerPaiements([['moyen' => 'especes', 'montant' => 6000, 'recu' => 5000]], 6000), 'reçue', 'reçu < montant');
refuse(fn () => Ventes::validerPaiements([['moyen' => 'cb', 'montant' => -6000]], -6000), 'invalide', 'montant négatif');
ok(Ventes::validerPaiements([], 0) === [], 'ticket à 0 € sans paiement');

section('Divers');
ok(Parametres::siretValide('88123456700017') && !Parametres::siretValide('12345678901234'), 'clé de Luhn du SIRET');
ok(Tenant::sousDomaineDepuisHote('BarberMax.gestionh.com:443') === 'barbermax', 'sous-domaine depuis l\'adresse');
ok(Tenant::sousDomaineDepuisHote('../etc.gestionh.com') === null && Tenant::sousDomaineDepuisHote('a.b') === null, 'adresses invalides refusées');
ok(Chaine::empreinte('', ['a' => 1, 'b' => 'é']) === hash('sha256', "\n" . '{"a":1,"b":"é"}'), 'empreinte : SHA-256 de la précédente + JSON canonique');
ok(Ventes::numeroAffiche(42) === '000042', 'numéro affiché sur 6 chiffres');

fin();
