<?php
/**
 * BarberPay — démarrage d'un point d'accès JSON de la caisse.
 *
 * Exige une session ouverte (gérant connecté) et, pour toute requête POST, le jeton CSRF dans l'en-tête
 * X-CSRF-Token. Après inclusion : $tenant, $con, $auth, $caisse, $parametres (tableau), api_corps().
 */
declare(strict_types=1);

use BarberPay\Caisse;
use BarberPay\Csrf;
use BarberPay\Parametres;

require __DIR__ . '/web.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function api_reponse(array $donnees, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($donnees, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function api_erreur(string $message, int $code = 400): never
{
    api_reponse(['ok' => false, 'erreur' => $message], $code);
}

/** Corps JSON de la requête (objet), 64 Ko au plus. */
function api_corps(): array
{
    $brut = file_get_contents('php://input', false, null, 0, 65536);
    if ($brut === false || $brut === '') {
        return [];
    }
    $donnees = json_decode($brut, true, 32);
    if (!is_array($donnees)) {
        api_erreur('Requête illisible.');
    }
    return $donnees;
}

set_exception_handler(static function (Throwable $erreur): void {
    error_log('BarberPay API : ' . $erreur->getMessage() . ' (' . $erreur->getFile() . ':' . $erreur->getLine() . ')');
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=UTF-8');
    }
    echo json_encode(['ok' => false, 'erreur' => 'Erreur du serveur. Réessayez.'], JSON_UNESCAPED_UNICODE);
});

if ($auth->utilisateur() === null) {
    api_erreur('Session expirée. Reconnectez-vous.', 401);
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !Csrf::valide($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    api_erreur('Jeton de sécurité invalide. Rechargez la page.', 403);
}

$parametres = (new Parametres($con, $tenant))->charger();
$caisse = new Caisse($con, (int) $parametres['verrouillage_minutes'], $journal);
