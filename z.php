<?php
/**
 * BarberPay — édition d'une clôture (Z du jour, mois, année), à l'écran ou imprimée en 80 mm (?imprimer=1).
 * Réservé au poste connecté.
 */
declare(strict_types=1);

use BarberPay\Clotures;
use BarberPay\Montant;
use BarberPay\Parametres;
use BarberPay\Tva;
use BarberPay\Ventes;
use function BarberPay\e;

require __DIR__ . '/app/web.php';

if ($auth->utilisateur() === null) {
    header('Location: /login.php');
    exit;
}
$c = (new Clotures($con, $tenant, $journal))->charger((int) ($_GET['id'] ?? 0));
if ($c === null) {
    bp_page_erreur(404, 'Clôture introuvable', 'Cette clôture n\'existe pas.');
}
$p = (new Parametres($con, $tenant))->charger();
$detail = json_decode((string) $c['detail'], true) ?: [];
$m = static fn (?int $v): string => $v === null ? '—' : Montant::format($v);
$titres = ['jour' => 'Clôture journalière (Z)', 'mois' => 'Clôture mensuelle', 'annee' => 'Clôture annuelle'];
$imprimer = isset($_GET['imprimer']);
$franchise = $c['regime_tva'] === Parametres::FRANCHISE;
$operateur = null;
if ($c['operateur_id'] !== null) {
    $requete = $con->prepare('SELECT prenom FROM utilisateurs WHERE id = ?');
    $id = (int) $c['operateur_id'];
    $requete->bind_param('i', $id);
    $requete->execute();
    $operateur = $requete->get_result()->fetch_row()[0] ?? null;
}
$date = static fn (string $v): string => substr($v, 8, 2) . '/' . substr($v, 5, 2) . '/' . substr($v, 0, 4) . (strlen($v) > 10 ? ' à ' . substr($v, 11, 5) : '');
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <?= BarberPay\icones_tete(false) ?>
    <title><?= e($titres[$c['type']] ?? 'Clôture') ?> n° <?= (int) $c['numero'] ?></title>
    <link rel="stylesheet" href="/public/css/ticket.css?v=<?= e(BarberPay\VERSION) ?>">
</head>
<body<?= $imprimer ? ' class="impression"' : '' ?>>
<main class="recu">
    <div class="liseret" aria-hidden="true"></div>
    <header class="salon">
        <h1><?= e((string) $p['nom_salon']) ?></h1>
        <?php if ($p['raison_sociale']): ?><p><?= e((string) $p['raison_sociale']) ?></p><?php endif; ?>
        <?php if ($p['siret']): ?><p>SIRET <?= e(preg_replace('/^(\d{3})(\d{3})(\d{3})(\d{5})$/', '$1 $2 $3 $4', (string) $p['siret'])) ?></p><?php endif; ?>
    </header>
    <section class="identite">
        <p class="titre"><?= e($titres[$c['type']] ?? 'Clôture') ?></p>
        <p>N° <?= (int) $c['numero'] ?> · période <?= e($c['type'] === 'jour' ? $date((string) $c['periode']) : (string) $c['periode']) ?></p>
        <p>Clôturée le <?= e($date((string) $c['date_cloture'])) ?><?= $c['automatique'] ? ' (automatique)' : ($operateur ? ' par ' . e($operateur) : '') ?></p>
    </section>

    <dl class="totaux">
        <div><dt>Tickets</dt><dd><?= $c['premier_ticket'] !== null ? 'n° ' . e(Ventes::numeroAffiche((int) $c['premier_ticket'])) . ' à ' . e(Ventes::numeroAffiche((int) $c['dernier_ticket'])) : 'aucun' ?></dd></div>
        <div><dt>Ventes (<?= (int) $c['nb_ventes'] ?>)</dt><dd><?= e($m((int) $c['ventes_ttc'])) ?></dd></div>
        <div><dt>Annulations (<?= (int) $c['nb_annulations'] ?>)</dt><dd><?= e($m((int) $c['annulations_ttc'])) ?></dd></div>
        <div><dt>Remises accordées</dt><dd><?= e($m((int) ($detail['remises'] ?? 0))) ?></dd></div>
        <div class="total"><dt>Net TTC</dt><dd><?= e($m((int) $c['net_ttc'])) ?></dd></div>
    </dl>

    <?php if ($franchise): ?>
        <p class="mention">TVA non applicable, art. 293 B du CGI</p>
    <?php else: ?>
        <table class="tva">
            <caption>TVA</caption>
            <thead><tr><th scope="col">Taux</th><th scope="col" class="montant">HT</th><th scope="col" class="montant">TVA</th><th scope="col" class="montant">TTC</th></tr></thead>
            <tbody>
            <?php foreach ($detail['par_taux'] ?? [] as $t): ?>
                <tr><td><?= e(Tva::libelle((int) $t['taux'])) ?></td><td class="montant"><?= e($m((int) $t['ht'])) ?></td><td class="montant"><?= e($m((int) $t['tva'])) ?></td><td class="montant"><?= e($m((int) $t['ttc'])) ?></td></tr>
            <?php endforeach; ?>
            <tr><td><strong>Total</strong></td><td class="montant"><?= e($m((int) $c['net_ht'])) ?></td><td class="montant"><?= e($m((int) $c['net_tva'])) ?></td><td class="montant"><?= e($m((int) $c['net_ttc'])) ?></td></tr>
            </tbody>
        </table>
    <?php endif; ?>

    <dl class="paiements">
        <?php foreach (Ventes::MOYENS as $code => $nom): ?>
            <div><dt><?= e($nom) ?></dt><dd><?= e($m((int) ($detail['par_moyen'][$code] ?? 0))) ?></dd></div>
        <?php endforeach; ?>
    </dl>
    <dl class="paiements">
        <div><dt>Services</dt><dd><?= e($m((int) ($detail['par_type']['service'] ?? 0))) ?></dd></div>
        <div><dt>Produits</dt><dd><?= e($m((int) ($detail['par_type']['produit'] ?? 0))) ?></dd></div>
        <?php foreach ($detail['par_barbier'] ?? [] as $b): ?>
            <div class="secondaire"><dt><?= e($b['prenom'] ?? 'Non attribué') ?></dt><dd><?= e($m((int) $b['ttc'])) ?></dd></div>
        <?php endforeach; ?>
    </dl>

    <?php if ($c['type'] === 'jour' && !$c['automatique']): ?>
        <dl class="paiements">
            <div><dt>Fond de caisse</dt><dd><?= e($m($c['fond'] === null ? null : (int) $c['fond'])) ?></dd></div>
            <div><dt>Espèces attendues</dt><dd><?= e($m((int) $c['especes_attendues'])) ?></dd></div>
            <div><dt>Espèces comptées</dt><dd><?= e($m($c['especes_comptees'] === null ? null : (int) $c['especes_comptees'])) ?></dd></div>
            <div class="total-ecart"><dt>Écart</dt><dd><?= e($m($c['ecart'] === null ? null : (int) $c['ecart'])) ?></dd></div>
        </dl>
    <?php endif; ?>

    <dl class="paiements">
        <div><dt>Grand total perpétuel</dt><dd><?= e($m((int) $c['grand_total'])) ?></dd></div>
        <div class="secondaire"><dt>en valeur absolue</dt><dd><?= e($m((int) $c['grand_total_absolu'])) ?></dd></div>
    </dl>

    <footer class="pied">
        <p class="technique">BarberPay <?= e((string) $c['version_logiciel']) ?><br>
            Signature <?= e(substr((string) $c['signature'], 0, 8)) ?> · empreinte <?= e(substr((string) $c['hash'], 0, 8)) ?></p>
    </footer>
</main>
<?php if (!$imprimer): ?>
    <p class="actions"><button type="button" onclick="window.print()">Imprimer</button> <a class="lien-retour-recu" href="/recette.php">Retour à la recette</a></p>
<?php else: ?>
    <script>window.addEventListener('load', function () { window.print(); });</script>
<?php endif; ?>
</body>
</html>
