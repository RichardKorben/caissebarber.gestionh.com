<?php
/**
 * BarberPay — démarrage d'une page web.
 *
 * Reconnaît le barber d'après l'adresse, ouvre sa base, démarre la session.
 * Mettre $pagePublique = true avant l'inclusion pour une page sans session (ticket en ligne).
 * Après inclusion : $tenant (Tenant), $con (mysqli, base du barber), $journal (Journal), $auth (Auth).
 */
declare(strict_types=1);

use BarberPay\Auth;
use BarberPay\Database;
use BarberPay\Journal;
use BarberPay\Session;
use BarberPay\Tenant;

require __DIR__ . '/bootstrap.php';

header('Content-Type: text/html; charset=UTF-8');

/** Page d'erreur minimale, sans rien révéler du fonctionnement interne. */
function bp_page_erreur(int $code, string $titre, string $message): never
{
    http_response_code($code);
    $titre = BarberPay\e($titre);
    $message = BarberPay\e($message);
    echo "<!doctype html><html lang=\"fr\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">"
        . "<title>{$titre} — BarberPay</title><link rel=\"stylesheet\" href=\"/public/css/barberpay.css\"></head>"
        . "<body class=\"page-centre\"><main class=\"carte-message\"><h1>{$titre}</h1><p>{$message}</p></main></body></html>";
    exit;
}

try {
    $sousDomaine = Tenant::sousDomaineDepuisHote((string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
    if ($sousDomaine === null) {
        bp_page_erreur(400, 'Adresse invalide', 'Cette adresse ne correspond à aucun salon.');
    }
    $tenant = Tenant::trouver(Database::commune(), $sousDomaine);
    if ($tenant === null) {
        bp_page_erreur(404, 'Salon introuvable', 'Aucun salon BarberPay n\'existe à cette adresse.');
    }
    if ($tenant->statut === Tenant::STATUT_PREPARATION) {
        bp_page_erreur(503, 'Espace en préparation', 'Votre espace BarberPay est en cours de création. Réessayez dans quelques instants.');
    }
    if (!$tenant->estActif()) {
        bp_page_erreur(403, 'Compte suspendu', 'Ce compte BarberPay est suspendu. Vos données restent conservées.');
    }

    $con = Database::barber($tenant->base);
    if (empty($pagePublique)) {
        Session::demarrer($tenant);
    }
    $journal = new Journal($con, $tenant);
    $auth = new Auth($con, $journal);
} catch (Throwable $erreur) {
    error_log('BarberPay : ' . $erreur->getMessage() . ' (' . $erreur->getFile() . ':' . $erreur->getLine() . ')');
    bp_page_erreur(500, 'Service indisponible', 'Une erreur est survenue. Réessayez dans un instant.');
}
