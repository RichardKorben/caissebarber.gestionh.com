<?php
/** BarberPay — verrouiller la caisse depuis l'Historique ou la Recette (formulaire POST avec jeton). */
declare(strict_types=1);

use BarberPay\Caisse;
use BarberPay\Csrf;
use BarberPay\Parametres;

require __DIR__ . '/app/web.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && Csrf::valide($_POST['csrf'] ?? null) && $auth->utilisateur() !== null) {
    (new Caisse($con, (int) (new Parametres($con, $tenant))->charger()['verrouillage_minutes']))->verrouiller();
}
header('Location: /', true, 303);
exit;
