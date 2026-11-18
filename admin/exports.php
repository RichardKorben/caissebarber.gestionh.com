<?php
declare(strict_types=1);

use BarberPay\Exports;
use BarberPay\Montant;
use BarberPay\Parametres;
use function BarberPay\e;

require __DIR__ . '/../app/admin.php';

$aujourdhui = new DateTimeImmutable('today');
$raccourcis = [
    'Ce mois' => [$aujourdhui->modify('first day of this month'), $aujourdhui],
    'Mois dernier' => [$aujourdhui->modify('first day of last month'), $aujourdhui->modify('last day of last month')],
    'Ce trimestre' => [new DateTimeImmutable($aujourdhui->format('Y') . '-' . sprintf('%02d', intdiv((int) $aujourdhui->format('n') - 1, 3) * 3 + 1) . '-01'), $aujourdhui],
    'Cette année' => [new DateTimeImmutable($aujourdhui->format('Y') . '-01-01'), $aujourdhui],
    'Année dernière' => [new DateTimeImmutable(((int) $aujourdhui->format('Y') - 1) . '-01-01'), new DateTimeImmutable(((int) $aujourdhui->format('Y') - 1) . '-12-31')],
];
$debut = (string) ($_GET['debut'] ?? $raccourcis['Ce mois'][0]->format('Y-m-d'));
$fin = (string) ($_GET['fin'] ?? $aujourdhui->format('Y-m-d'));
try {
    Exports::periode($debut, $fin);
} catch (InvalidArgumentException) {
    $debut = $raccourcis['Ce mois'][0]->format('Y-m-d');
    $fin = $aujourdhui->format('Y-m-d');
}
$annee = isset($_GET['annee']) && preg_match('/^\d{4}$/', (string) $_GET['annee']) ? (int) $_GET['annee'] : (int) $aujourdhui->format('Y');

$exports = new Exports($con, $tenant);
$urssaf = $exports->urssaf($annee);
$p = (new Parametres($con, $tenant))->charger();
$franchise = $p['regime_tva'] === Parametres::FRANCHISE;
$caAnnee = $exports->chiffreAnnee((int) $aujourdhui->format('Y'));
$m = static fn (int $c): string => Montant::format($c);
$moisFr = ['Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];
$lien = static fn (string $quoi): string => '/admin/telecharger.php?quoi=' . $quoi . '&amp;debut=' . e($debut) . '&amp;fin=' . e($fin);
$jauge = static function (int $valeur, int $seuil): array {
    $pourcent = $seuil > 0 ? (int) floor($valeur * 100 / $seuil) : 0;
    return [$pourcent, $pourcent >= 100 ? 'depasse' : ($pourcent >= 80 ? 'proche' : 'ok')];
};

admin_haut('Exports', 'exports');
?>
<div class="entete-page">
    <div>
        <h1>Exports</h1>
        <p class="sous-titre">Pour votre comptabilité, l'URSSAF et un éventuel contrôle fiscal. Les tickets du mode formation ne sont jamais exportés.</p>
    </div>
</div>

<section class="carte" aria-labelledby="titre-periode">
    <h2 id="titre-periode">Période</h2>
    <div class="raccourcis-periode">
        <?php foreach ($raccourcis as $libelle => [$d, $f]): ?>
            <a class="bouton<?= $d->format('Y-m-d') === $debut && $f->format('Y-m-d') === $fin ? ' bouton-principal' : '' ?>" href="?debut=<?= e($d->format('Y-m-d')) ?>&amp;fin=<?= e($f->format('Y-m-d')) ?>&amp;annee=<?= $annee ?>"><?= e($libelle) ?></a>
        <?php endforeach; ?>
    </div>
    <form method="get" class="formulaire-periode">
        <div class="champ"><label for="debut">Du</label><input type="date" id="debut" name="debut" value="<?= e($debut) ?>"></div>
        <div class="champ"><label for="fin">Au (inclus)</label><input type="date" id="fin" name="fin" value="<?= e($fin) ?>"></div>
        <input type="hidden" name="annee" value="<?= $annee ?>">
        <button class="bouton" type="submit">Appliquer</button>
    </form>

    <ul class="liste liste-exports">
        <li class="ligne"><div class="ligne-infos"><span class="ligne-nom">Livre des recettes</span><span class="ligne-meta">Obligatoire pour les micro-entrepreneurs : date, nature, montant et règlement de chaque recette.</span></div>
            <div class="ligne-actions"><a class="bouton" href="/admin/livre.php?debut=<?= e($debut) ?>&amp;fin=<?= e($fin) ?>"><?= icone('oeil', 18) ?>Voir / imprimer</a><a class="bouton" href="<?= $lien('livre') ?>"><?= icone('export', 18) ?>CSV</a></div></li>
        <li class="ligne"><div class="ligne-infos"><span class="ligne-nom">Journal des ventes</span><span class="ligne-meta">Pour l'expert-comptable : par jour, la TVA par taux et les règlements par moyen.</span></div>
            <div class="ligne-actions"><a class="bouton" href="<?= $lien('journal') ?>"><?= icone('export', 18) ?>CSV</a></div></li>
        <li class="ligne"><div class="ligne-infos"><span class="ligne-nom">E-reporting (ventes aux particuliers)</span><span class="ligne-meta">Totaux journaliers par taux, à déposer sur votre plateforme agréée (obligatoire en septembre 2027). Format provisoire.</span></div>
            <div class="ligne-actions"><a class="bouton" href="<?= $lien('ereporting') ?>"><?= icone('export', 18) ?>CSV</a><a class="bouton" href="<?= $lien('ereporting-json') ?>">JSON</a></div></li>
        <li class="ligne"><div class="ligne-infos"><span class="ligne-nom">Export pour un contrôle fiscal</span><span class="ligne-meta">Toutes les données de la période (tickets, clôtures, journal), la clé publique et la notice de vérification, en ZIP.</span></div>
            <div class="ligne-actions"><a class="bouton" href="<?= $lien('fiscal') ?>"><?= icone('export', 18) ?>ZIP</a></div></li>
    </ul>
</section>

<section class="carte" aria-labelledby="titre-urssaf">
    <div class="entete-carte">
        <h2 id="titre-urssaf">Récapitulatif URSSAF <?= $annee ?></h2>
        <nav class="annees" aria-label="Année">
            <a class="bouton" href="?debut=<?= e($debut) ?>&amp;fin=<?= e($fin) ?>&amp;annee=<?= $annee - 1 ?>"><?= $annee - 1 ?></a>
            <?php if ($annee < (int) $aujourdhui->format('Y')): ?><a class="bouton" href="?debut=<?= e($debut) ?>&amp;fin=<?= e($fin) ?>&amp;annee=<?= $annee + 1 ?>"><?= $annee + 1 ?></a><?php endif; ?>
        </nav>
    </div>
    <p class="aide">Chiffre d'affaires encaissé, à déclarer : prestations de services d'un côté, ventes de marchandises (produits) de l'autre.</p>
    <table class="tableau-admin">
        <thead><tr><th scope="col">Mois</th><th scope="col">Prestations de services</th><th scope="col">Ventes de produits</th><th scope="col">Total</th></tr></thead>
        <tbody>
        <?php $i = 0; foreach ($urssaf['mois'] as $mois => $v): ?>
            <tr><td><?= e($moisFr[$i++]) ?></td><td><?= e($m($v['services'])) ?></td><td><?= e($m($v['produits'])) ?></td><td><?= e($m($v['total'])) ?></td></tr>
            <?php if ($i % 3 === 0): $t = $urssaf['trimestres'][intdiv($i, 3)]; ?>
                <tr class="sous-total"><td><?= intdiv($i, 3) ?><sup><?= intdiv($i, 3) === 1 ? 'er' : 'e' ?></sup> trimestre</td><td><?= e($m($t['services'])) ?></td><td><?= e($m($t['produits'])) ?></td><td><?= e($m($t['total'])) ?></td></tr>
            <?php endif; ?>
        <?php endforeach; ?>
        <tr class="total"><td>Année <?= $annee ?></td><td><?= e($m($urssaf['annee']['services'])) ?></td><td><?= e($m($urssaf['annee']['produits'])) ?></td><td><?= e($m($urssaf['annee']['total'])) ?></td></tr>
        </tbody>
    </table>
</section>

<section class="carte" aria-labelledby="titre-seuils">
    <h2 id="titre-seuils">Seuils de franchise de TVA — <?= e($aujourdhui->format('Y')) ?></h2>
    <p class="aide"><?= $franchise ? 'Vous êtes en franchise : au-delà du seuil majoré, la TVA est due dès le jour du dépassement.' : 'Vous êtes assujetti à la TVA : ces seuils sont donnés à titre indicatif.' ?>
        Seuils modifiables dans Salon.</p>
    <?php foreach ([
        ['Prestations de services', $caAnnee['services'], (int) $p['seuil_services'], (int) $p['seuil_services_majore']],
        ['Total (ventes et services)', $caAnnee['total'], (int) $p['seuil_ventes'], (int) $p['seuil_ventes_majore']],
    ] as [$libelle, $valeur, $seuil, $majore]):
        [$pourcent, $etat] = $jauge($valeur, $seuil); ?>
        <div class="jauge jauge-<?= $etat ?>">
            <div class="jauge-texte"><strong><?= e($libelle) ?></strong><span><?= e($m($valeur)) ?> sur <?= e($m($seuil)) ?> (seuil majoré <?= e($m($majore)) ?>) — <?= $pourcent ?> %</span></div>
            <div class="jauge-barre" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= min(100, $pourcent) ?>" aria-label="<?= e($libelle) ?>"><span style="width:<?= min(100, $pourcent) ?>%"></span></div>
        </div>
    <?php endforeach; ?>
</section>
<?php admin_bas(); ?>
