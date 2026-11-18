<?php
/**
 * BarberPay — ticket en ligne (lien du QR code) et version imprimable (80 mm).
 *
 *   /t/<jeton>               ticket consultable par le client, sans connexion
 *   /t/<jeton>?imprimer=1    ouvre l'impression (utilisé par la caisse)
 *
 * Le jeton (128 bits aléatoires) est le seul accès : aucune liste, aucune donnée personnelle.
 * Les mentions du salon viennent de l'en-tête signé avec le ticket (identité du jour de la vente).
 */
declare(strict_types=1);

use BarberPay\Montant;
use BarberPay\Parametres;
use BarberPay\Tva;
use BarberPay\Ventes;
use function BarberPay\e;

$pagePublique = true;
require __DIR__ . '/app/web.php';

header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex, nofollow');

$ticket = (new Ventes($con, $tenant, $journal))->chargerParJeton((string) ($_GET['j'] ?? ''));
if ($ticket === null) {
    bp_page_erreur(404, 'Ticket introuvable', 'Ce lien de ticket n\'existe pas ou n\'est plus valide.');
}

$salon = json_decode((string) $ticket['entete'], true) ?: [];
$annulation = $ticket['type'] === 'annulation';
$franchise = $ticket['regime_tva'] === Parametres::FRANCHISE;
$formation = $ticket['chaine'] === 'formation';
$prenoms = [];
foreach ($con->query('SELECT id, prenom FROM utilisateurs') as $u) {
    $prenoms[(int) $u['id']] = $u['prenom'];
}
$barbiers = array_values(array_unique(array_filter(array_map(static fn (array $l) => $prenoms[$l['barbier_id']] ?? null, $ticket['lignes']))));
$date = DateTime::createFromFormat('Y-m-d H:i:s', (string) $ticket['date_ticket']);
$imprimer = isset($_GET['imprimer']);
$duplicata = null;
if (isset($_GET['duplicata']) && ctype_digit((string) $_GET['duplicata'])) {
    $requete = $con->prepare('SELECT numero, cree_le FROM duplicatas WHERE ticket_id = ? AND numero = ?');
    $idTicket = (int) $ticket['id'];
    $numeroDuplicata = (int) $_GET['duplicata'];
    $requete->bind_param('ii', $idTicket, $numeroDuplicata);
    $requete->execute();
    $duplicata = $requete->get_result()->fetch_assoc() ?: null;
}
$m = static fn (int $c): string => Montant::format($c);
$libelleRemise = static fn (?string $type, ?int $valeur): string => $type === 'pct'
    ? ($valeur === 10000 ? 'offert' : rtrim(rtrim(number_format($valeur / 100, 2, ',', ''), '0'), ',') . ' %')
    : '';
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Ticket n° <?= e(Ventes::numeroAffiche((int) $ticket['numero'])) ?> — <?= e((string) ($salon['nom_salon'] ?? '')) ?></title>
    <link rel="stylesheet" href="/public/css/ticket.css?v=<?= e(BarberPay\VERSION) ?>">
</head>
<body<?= $imprimer ? ' class="impression"' : '' ?>>
<main class="recu">
    <div class="liseret" aria-hidden="true"></div>
    <header class="salon">
        <h1><?= e((string) ($salon['nom_salon'] ?? '')) ?></h1>
        <?php if (!empty($salon['raison_sociale'])): ?><p><?= e($salon['raison_sociale']) ?></p><?php endif; ?>
        <?php if (!empty($salon['adresse'])): ?><p><?= e($salon['adresse']) ?></p><?php endif; ?>
        <?php if (!empty($salon['code_postal']) || !empty($salon['ville'])): ?><p><?= e(trim(($salon['code_postal'] ?? '') . ' ' . ($salon['ville'] ?? ''))) ?></p><?php endif; ?>
        <?php if (!empty($salon['telephone'])): ?><p>Tél. <?= e($salon['telephone']) ?></p><?php endif; ?>
        <?php if (!empty($salon['siret'])): ?><p>SIRET <?= e(preg_replace('/^(\d{3})(\d{3})(\d{3})(\d{5})$/', '$1 $2 $3 $4', (string) $salon['siret'])) ?></p><?php endif; ?>
        <?php if (!$franchise && !empty($salon['tva_intracom'])): ?><p>TVA <?= e($salon['tva_intracom']) ?></p><?php endif; ?>
    </header>

    <?php if ($formation): ?><p class="bandeau">MODE FORMATION — SANS VALEUR</p><?php endif; ?>
    <?php if ($duplicata): ?><p class="bandeau">DUPLICATA n° <?= (int) $duplicata['numero'] ?> — imprimé le <?= e(date('d/m/Y à H:i', strtotime((string) $duplicata['cree_le']))) ?></p><?php endif; ?>

    <section class="identite">
        <p class="titre"><?= $annulation ? 'Annulation' : 'Ticket' ?> n° <?= e(Ventes::numeroAffiche((int) $ticket['numero'])) ?></p>
        <p><?= $date ? e($date->format('d/m/Y')) . ' à ' . e($date->format('H:i')) : '' ?></p>
        <?php if ($annulation && $ticket['origine']): ?>
            <p>Annule le ticket n° <?= e(Ventes::numeroAffiche((int) $ticket['origine']['numero'])) ?></p>
        <?php endif; ?>
    </section>

    <?php if ($ticket['annule_par']): ?>
        <p class="bandeau">Ticket annulé par le ticket n° <?= e(Ventes::numeroAffiche((int) $ticket['annule_par']['numero'])) ?></p>
    <?php endif; ?>

    <table class="lignes">
        <caption class="visuellement-cache">Détail des articles</caption>
        <thead><tr><th scope="col">Article</th><th scope="col" class="montant">Total</th></tr></thead>
        <tbody>
        <?php foreach ($ticket['lignes'] as $l): ?>
            <tr>
                <td>
                    <span class="libelle"><?= e((string) $l['libelle']) ?></span>
                    <span class="detail"><?= (int) $l['quantite'] ?> × <?= e($m((int) $l['prix_unitaire'])) ?><?php
                        if (!$franchise && count($ticket['tva']) > 1): ?> · TVA <?= e(Tva::libelle((int) $l['taux_tva'])) ?><?php endif; ?></span>
                    <?php if ((int) $l['remise_ligne'] !== 0): ?>
                        <span class="detail">Remise <?= e($libelleRemise($l['remise_type'], $l['remise_valeur'])) ?> −<?= e($m(abs((int) $l['remise_ligne']))) ?><?= $l['remise_motif'] ? ' (' . e($l['remise_motif']) . ')' : '' ?></span>
                    <?php endif; ?>
                </td>
                <td class="montant"><?= e($m((int) $l['total_ttc'] + (int) $l['part_remise_ticket'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <dl class="totaux">
        <?php if ((int) $ticket['remise_ticket'] !== 0): ?>
            <div><dt>Sous-total</dt><dd><?= e($m((int) $ticket['sous_total'])) ?></dd></div>
            <div><dt>Remise <?= e($libelleRemise($ticket['remise_type'], $ticket['remise_valeur'])) ?><?= $ticket['remise_motif'] ? ' (' . e($ticket['remise_motif']) . ')' : '' ?></dt><dd>−<?= e($m(abs((int) $ticket['remise_ticket']))) ?></dd></div>
        <?php endif; ?>
        <div class="total"><dt>Total TTC</dt><dd><?= e($m((int) $ticket['total_ttc'])) ?></dd></div>
    </dl>

    <?php if ($franchise): ?>
        <p class="mention">TVA non applicable, art. 293 B du CGI</p>
    <?php else: ?>
        <table class="tva">
            <caption>Détail de la TVA</caption>
            <thead><tr><th scope="col">Taux</th><th scope="col" class="montant">HT</th><th scope="col" class="montant">TVA</th><th scope="col" class="montant">TTC</th></tr></thead>
            <tbody>
            <?php foreach ($ticket['tva'] as $t): ?>
                <tr><td><?= e(Tva::libelle((int) $t['taux'])) ?></td><td class="montant"><?= e($m((int) $t['base_ht'])) ?></td><td class="montant"><?= e($m((int) $t['tva'])) ?></td><td class="montant"><?= e($m((int) $t['ttc'])) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if ($ticket['paiements']): ?>
        <dl class="paiements">
            <?php foreach ($ticket['paiements'] as $p): ?>
                <div><dt><?= $annulation ? 'Remboursé — ' : '' ?><?= e(Ventes::MOYENS[$p['moyen']] ?? $p['moyen']) ?></dt><dd><?= e($m((int) $p['montant'])) ?></dd></div>
                <?php if ($p['recu'] !== null): ?>
                    <div class="secondaire"><dt>Reçu</dt><dd><?= e($m((int) $p['recu'])) ?></dd></div>
                    <div class="secondaire"><dt>Rendu</dt><dd><?= e($m((int) $p['rendu'])) ?></dd></div>
                <?php endif; ?>
            <?php endforeach; ?>
        </dl>
    <?php endif; ?>

    <footer class="pied">
        <?php if ($barbiers): ?><p>Votre barbier : <?= e(implode(', ', $barbiers)) ?></p><?php endif; ?>
        <?php if (!empty($salon['mention_ticket'])): ?><p class="merci"><?= e($salon['mention_ticket']) ?></p><?php endif; ?>
        <p class="technique">BarberPay <?= e((string) $ticket['version_logiciel']) ?> · <?= (int) abs((int) $ticket['nb_articles']) ?> article(s)<br>
            Signature <?= e(substr((string) $ticket['signature'], 0, 8)) ?> · empreinte <?= e(substr((string) $ticket['hash'], 0, 8)) ?></p>
    </footer>
</main>
<?php if (!$imprimer): ?>
    <p class="actions"><button type="button" onclick="window.print()">Imprimer ou enregistrer en PDF</button></p>
<?php else: ?>
    <script>window.addEventListener('load', function () { window.print(); });</script>
<?php endif; ?>
</body>
</html>
