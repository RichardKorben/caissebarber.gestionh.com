<?php
/**
 * BarberPay — démarrage des écrans du poste de caisse (Historique, Recette) et mise en page commune.
 * Exige une session ouverte et une caisse déverrouillée (sinon retour à la caisse, qui affiche le verrouillage).
 * Après inclusion : $tenant, $con, $journal, $auth, $utilisateur, $parametres, $caisse, $operateur, $clotures.
 */
declare(strict_types=1);

use BarberPay\Caisse;
use BarberPay\Clotures;
use BarberPay\Csrf;
use BarberPay\Parametres;
use function BarberPay\e;

require __DIR__ . '/web.php';
require_once __DIR__ . '/admin_outils.php';

$utilisateur = $auth->utilisateur();
if ($utilisateur === null) {
    header('Location: /login.php');
    exit;
}
$parametres = (new Parametres($con, $tenant))->charger();
$caisse = new Caisse($con, (int) $parametres['verrouillage_minutes'], $journal);
$operateur = $caisse->operateur();
if ($operateur === null) {
    header('Location: /');
    exit;
}
$caisse->toucher();
$clotures = new Clotures($con, $tenant, $journal);
$clotures->rattraper();

function poste_haut(string $titre, string $actif): void
{
    global $tenant, $operateur, $caisse;
    $liens = [
        'caisse' => ['/', 'Caisse', 'caisse'],
        'historique' => ['/historique.php', 'Historique', 'historique'],
        'recette' => ['/recette.php', 'Recette', 'recette'],
    ];
    ?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <meta name="csrf" content="<?= e(Csrf::jeton()) ?>">
    <title><?= e($titre) ?> — <?= e($tenant->nomSalon) ?></title>
    <link rel="stylesheet" href="/public/css/barberpay.css?v=<?= e(BarberPay\VERSION) ?>">
    <link rel="stylesheet" href="/public/css/admin.css?v=<?= e(BarberPay\VERSION) ?>">
    <link rel="stylesheet" href="/public/css/poste.css?v=<?= e(BarberPay\VERSION) ?>">
</head>
<body class="admin poste">
<a class="lien-evitement" href="#contenu">Aller au contenu</a>
<div class="admin-cadre">
    <nav class="barre-laterale" aria-label="Poste de caisse">
        <a class="logo-lien" href="/" aria-label="Retour à la caisse">
            <span class="logo-poteau" aria-hidden="true">
                <span class="poteau-dome"></span><span class="poteau-embout"></span>
                <span class="poteau-cylindre"></span><span class="poteau-embout"></span>
            </span>
            <span class="marque">BARBER</span>
        </a>
        <ul class="nav-principale">
            <?php foreach ($liens as $cle => [$url, $libelle, $icone]): ?>
                <li><a class="bouton-nav<?= $cle === $actif ? ' actif' : '' ?>" href="<?= e($url) ?>"<?= $cle === $actif ? ' aria-current="page"' : '' ?>><?= icone($icone, 26) ?><span><?= e($libelle) ?></span></a></li>
            <?php endforeach; ?>
        </ul>
        <div class="nav-outils">
            <?php if ($operateur['role'] === 'gerant'): ?>
                <a class="bouton-outil" href="/admin/"><?= icone('admin', 20) ?><span>Admin</span></a>
            <?php endif; ?>
            <?php if ($caisse->verrouillageActif()): ?>
                <form method="post" action="/verrouiller.php">
                    <?= Csrf::champ() ?>
                    <button type="submit" class="bouton-outil"><?= icone('cadenas', 20) ?><span>Verrouiller</span></button>
                </form>
            <?php endif; ?>
        </div>
    </nav>
    <main id="contenu" class="admin-contenu">
    <?php
}

function poste_bas(): void
{
    ?>
    </main>
</div>
<dialog class="fenetre-poste" id="fenetre" aria-labelledby="fenetre-titre"><form method="dialog" id="fenetre-contenu"></form></dialog>
<div class="toast" id="toast" role="status" aria-live="polite" hidden></div>
<script src="/public/js/poste.js?v=<?= e(BarberPay\VERSION) ?>"></script>
</body>
</html>
    <?php
}
