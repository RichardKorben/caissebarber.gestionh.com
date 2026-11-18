<?php
declare(strict_types=1);

use BarberPay\Csrf;
use function BarberPay\e;

require __DIR__ . '/app/web.php';

$utilisateur = $auth->utilisateur();
if ($utilisateur === null) {
    header('Location: /login.php');
    exit;
}
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title><?= e($tenant->nomSalon) ?> — BarberPay</title>
    <link rel="stylesheet" href="/public/css/barberpay.css?v=<?= e(BarberPay\VERSION) ?>">
</head>
<body class="page-centre">
    <!-- Étape 0 : page d'accueil provisoire. L'écran de caisse arrive à l'étape 2. -->
    <main class="carte-connexion">
        <div class="logo-poteau" aria-hidden="true">
            <span class="poteau-dome"></span><span class="poteau-embout"></span>
            <span class="poteau-cylindre poteau-anime"></span>
            <span class="poteau-embout"></span>
        </div>
        <p class="marque">BARBERPAY</p>
        <h1><?= e($tenant->nomSalon) ?></h1>
        <p class="texte-clair">Connecté : <strong><?= e(trim($utilisateur['prenom'] . ' ' . ($utilisateur['nom'] ?? ''))) ?></strong> (gérant)</p>
        <p class="texte-discret">La caisse sera disponible à l'étape 2. En attendant, préparez votre catalogue, votre équipe et les informations du salon. Version <?= e(BarberPay\VERSION) ?>.</p>
        <a class="bouton-or" href="/admin/" style="text-decoration:none">Administration</a>
        <form method="post" action="/logout.php">
            <?= Csrf::champ() ?>
            <button type="submit" class="bouton-secondaire">Se déconnecter</button>
        </form>
    </main>
</body>
</html>
