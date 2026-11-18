<?php
/**
 * BarberPay — démarrage d'une page d'administration (réservée au gérant connecté) et mise en page commune.
 * Après inclusion : $tenant, $con, $auth, $utilisateur.
 */
declare(strict_types=1);

use BarberPay\Csrf;
use BarberPay\Flash;
use function BarberPay\e;

require __DIR__ . '/web.php';
require_once __DIR__ . '/admin_outils.php';

$utilisateur = $auth->utilisateur();
if ($utilisateur === null) {
    header('Location: /login.php');
    exit;
}
if ($utilisateur['role'] !== 'gerant') {
    bp_page_erreur(403, 'Accès réservé', 'L\'administration est réservée au gérant du salon.');
}
// Si la caisse se verrouille par PIN, il faut qu'un gérant soit à la caisse (un barbier ne doit pas entrer
// dans l'admin depuis le poste ouvert par le gérant).
$caisseAdmin = new BarberPay\Caisse($con, (int) (new BarberPay\Parametres($con, $tenant))->charger()['verrouillage_minutes']);
if ($caisseAdmin->verrouillageActif()) {
    $operateurAdmin = $caisseAdmin->operateur();
    if ($operateurAdmin === null || $operateurAdmin['role'] !== 'gerant') {
        bp_page_erreur(403, 'Code gérant nécessaire', 'Déverrouillez la caisse avec un code PIN de gérant pour ouvrir l\'administration.');
    }
    $caisseAdmin->toucher();
}

/** Requête POST avec un jeton valide ? Sinon message et redirection vers la même page. */
function admin_post(): bool
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return false;
    }
    if (!Csrf::valide($_POST['csrf'] ?? null)) {
        Flash::erreur('La page a expiré. Recommencez.');
        admin_rediriger($_SERVER['REQUEST_URI'] ?? '/admin/');
    }
    // Les formulaires de l'admin n'envoient que des valeurs simples : on écarte les tableaux (name="x[]").
    $_POST = array_filter($_POST, 'is_string');
    return true;
}

function admin_rediriger(string $url): never
{
    // Uniquement des adresses du site (pas de redirection vers un autre domaine).
    if (!str_starts_with($url, '/') || str_starts_with($url, '//')) {
        $url = '/admin/';
    }
    header('Location: ' . $url, true, 303);
    exit;
}

function admin_haut(string $titre, string $actif): void
{
    global $tenant, $utilisateur;
    $liens = [
        'catalogue' => ['/admin/catalogue.php', 'Catalogue', 'catalogue'],
        'equipe' => ['/admin/equipe.php', 'Équipe', 'equipe'],
        'salon' => ['/admin/salon.php', 'Salon', 'salon'],
        'exports' => ['/admin/exports.php', 'Exports', 'export'],
        'conformite' => ['/admin/conformite.php', 'Conformité', 'bouclier'],
    ];
    ?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <?= BarberPay\icones_tete() ?>
    <title><?= e($titre) ?> — Admin — <?= e($tenant->nomSalon) ?></title>
    <link rel="stylesheet" href="/public/css/barberpay.css?v=<?= e(BarberPay\VERSION) ?>">
    <link rel="stylesheet" href="/public/css/admin.css?v=<?= e(BarberPay\VERSION) ?>">
</head>
<body class="admin">
<a class="lien-evitement" href="#contenu">Aller au contenu</a>
<div class="admin-cadre">
    <nav class="barre-laterale" aria-label="Administration">
        <a class="logo-lien" href="/" aria-label="Retour à la caisse">
            <?= BarberPay\embleme() ?>
            <span class="marque">ADMIN</span>
        </a>
        <ul class="nav-principale">
            <?php foreach ($liens as $cle => [$url, $libelle, $icone]): ?>
                <li><a class="bouton-nav<?= $cle === $actif ? ' actif' : '' ?>" href="<?= e($url) ?>"<?= $cle === $actif ? ' aria-current="page"' : '' ?>><?= icone($icone, 26) ?><span><?= e($libelle) ?></span></a></li>
            <?php endforeach; ?>
        </ul>
        <div class="nav-outils">
            <a class="bouton-outil" href="/"><?= icone('caisse', 20) ?><span>Caisse</span></a>
            <form method="post" action="/logout.php">
                <?= Csrf::champ() ?>
                <button type="submit" class="bouton-outil"><?= icone('sortie', 20) ?><span>Déconnexion</span></button>
            </form>
        </div>
    </nav>
    <main id="contenu" class="admin-contenu">
        <?php foreach (Flash::lire() as [$type, $message]): ?>
            <p class="flash flash-<?= e($type) ?>" role="<?= $type === 'erreur' ? 'alert' : 'status' ?>"><?= e($message) ?></p>
        <?php endforeach; ?>
    <?php
}

function admin_bas(): void
{
    ?>
    </main>
</div>
</body>
</html>
    <?php
}
