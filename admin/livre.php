<?php
/** BarberPay — livre des recettes à l'écran, imprimable en A4 (gérant). */
declare(strict_types=1);

use BarberPay\Exports;
use BarberPay\Journal;
use BarberPay\Montant;
use BarberPay\Parametres;
use function BarberPay\e;

require __DIR__ . '/../app/admin.php';

$saisieDebut = (string) ($_GET['debut'] ?? '');
$saisieFin = (string) ($_GET['fin'] ?? '');
try {
    [$debut, $fin] = Exports::periode($saisieDebut, $saisieFin);
} catch (InvalidArgumentException $e) {
    BarberPay\Flash::erreur($e->getMessage());
    admin_rediriger('/admin/exports.php');
}
$lignes = (new Exports($con, $tenant))->livreRecettes($debut, $fin);
$journal->ecrire(Journal::EXPORT, ['export' => 'livre (écran)', 'du' => $debut, 'au' => $saisieFin], (int) $utilisateur['id']);
$p = (new Parametres($con, $tenant))->charger();
$total = array_sum(array_column($lignes, 'montant'));
$fr = static fn (string $d): string => substr($d, 8, 2) . '/' . substr($d, 5, 2) . '/' . substr($d, 0, 4);

admin_haut('Livre des recettes', 'exports');
?>
<a class="lien-retour non-imprime" href="/admin/exports.php?debut=<?= e($saisieDebut) ?>&amp;fin=<?= e($saisieFin) ?>"><?= icone('retour', 18) ?>Exports</a>
<div class="entete-page">
    <div>
        <h1>Livre des recettes</h1>
        <p class="sous-titre"><?= e((string) ($p['raison_sociale'] ?: $p['nom_salon'])) ?><?= $p['siret'] ? ' · SIRET ' . e((string) $p['siret']) : '' ?> · du <?= e($fr($saisieDebut)) ?> au <?= e($fr($saisieFin)) ?></p>
    </div>
    <button type="button" class="bouton bouton-principal non-imprime" onclick="window.print()"><?= icone('imprimante', 18) ?>Imprimer</button>
</div>
<?php if (!$lignes): ?>
    <div class="vide"><p>Aucune recette sur cette période.</p></div>
<?php else: ?>
    <table class="tableau-admin livre">
        <thead><tr><th scope="col">Date</th><th scope="col">N°</th><th scope="col">Nature</th><th scope="col">Détail</th><th scope="col">Règlement</th><th scope="col">Montant</th></tr></thead>
        <tbody>
        <?php foreach ($lignes as $l): ?>
            <tr<?= $l['montant'] < 0 ? ' class="negatif"' : '' ?>><td><?= e($l['date']) ?> <?= e($l['heure']) ?></td><td><?= e($l['numero']) ?></td><td><?= e($l['nature']) ?></td><td><?= e($l['detail']) ?></td><td><?= e($l['reglement']) ?></td><td><?= e(Montant::format($l['montant'])) ?></td></tr>
        <?php endforeach; ?>
        <tr class="total"><td colspan="5">Total de la période (<?= count($lignes) ?> pièce<?= count($lignes) > 1 ? 's' : '' ?>)</td><td><?= e(Montant::format($total)) ?></td></tr>
        </tbody>
    </table>
<?php endif; ?>
<?php admin_bas(); ?>
