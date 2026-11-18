<?php
/** Compare app/src/Calcul.php avec public/js/calcul.js sur les cas produits par tests/calcul.js. */
declare(strict_types=1);

require __DIR__ . '/lib.php';

$donnees = json_decode((string) file_get_contents($argv[1] ?? ''), true);
if (!is_array($donnees)) {
    fwrite(STDERR, "Usage : php tests/calcul.php <fichier JSON produit par tests/calcul.js>\n");
    exit(2);
}
$differences = 0;
foreach ($donnees as $i => $d) {
    $lignes = array_map(static fn (array $l): array => ['prix' => $l['prix'], 'quantite' => $l['quantite'], 'taux' => $l['taux'], 'remise' => $l['remise'] ?? null], $d['cas']['lignes']);
    $php = BarberPay\Calcul::ticket($lignes, $d['cas']['remise']);
    if (json_encode($php) !== json_encode($d['js']) && $differences++ < 3) {
        echo "  Différence sur le cas $i :\n    PHP " . json_encode($php) . "\n    JS  " . json_encode($d['js']) . "\n";
    }
}
section('Calcul PHP ↔ JavaScript');
ok($differences === 0, count($donnees) . ' tickets : résultats identiques au centime', $differences . ' différence(s)');
fin();
