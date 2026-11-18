<?php
declare(strict_types=1);

use BarberPay\Auth;
use BarberPay\Csrf;

require __DIR__ . '/app/web.php';

// Déconnexion uniquement par formulaire POST avec jeton (un lien piégé ne peut pas déconnecter).
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && Csrf::valide($_POST['csrf'] ?? null)) {
    $qui = (int) ($_SESSION['operateur_id'] ?? $_SESSION['utilisateur_id'] ?? 0);
    if ($qui > 0) {
        $journal->ecrire(BarberPay\Journal::DECONNEXION, [], $qui);
    }
    Auth::deconnecter();
}
header('Location: /login.php');
exit;
