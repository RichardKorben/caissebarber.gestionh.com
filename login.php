<?php
declare(strict_types=1);

use BarberPay\Csrf;
use function BarberPay\e;

require __DIR__ . '/app/web.php';

if ($auth->utilisateur() !== null) {
    header('Location: /');
    exit;
}

$erreur = '';
$email = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $email = (string) ($_POST['email'] ?? '');
    if (!Csrf::valide($_POST['csrf'] ?? null)) {
        $erreur = 'La page a expiré. Saisissez à nouveau vos identifiants.';
    } else {
        $resultat = $auth->connecter($email, (string) ($_POST['mot_de_passe'] ?? ''), (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        if ($resultat['ok']) {
            header('Location: /');
            exit;
        }
        $erreur = $resultat['erreur'] ?? 'Connexion impossible.';
    }
}
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Connexion — <?= e($tenant->nomSalon) ?></title>
    <link rel="stylesheet" href="/public/css/barberpay.css?v=<?= e(BarberPay\VERSION) ?>">
</head>
<body class="page-centre">
    <main class="carte-connexion">
        <div class="logo-poteau" aria-hidden="true">
            <span class="poteau-dome"></span><span class="poteau-embout"></span>
            <span class="poteau-cylindre poteau-anime"></span>
            <span class="poteau-embout"></span>
        </div>
        <p class="marque">BARBERPAY</p>
        <h1><?= e($tenant->nomSalon) ?></h1>

        <form method="post" action="/login.php" novalidate>
            <?= Csrf::champ() ?>
            <?php if ($erreur !== ''): ?>
                <p class="alerte" role="alert"><?= e($erreur) ?></p>
            <?php endif; ?>
            <label for="email">E-mail</label>
            <input id="email" name="email" type="email" autocomplete="username" required value="<?= e($email) ?>" autofocus>
            <label for="mot_de_passe">Mot de passe</label>
            <input id="mot_de_passe" name="mot_de_passe" type="password" autocomplete="current-password" required>
            <button type="submit" class="bouton-or">Se connecter</button>
        </form>
    </main>
</body>
</html>
