<?php
/**
 * BarberPay — chargement commun (web et ligne de commande).
 *
 * Ne fait aucune sortie et ne démarre pas de session : voir app/web.php pour les pages.
 */
declare(strict_types=1);

namespace BarberPay;

const VERSION = '0.3.0';
const RACINE = __DIR__ . '/..';
const DOSSIER_APP = __DIR__;
const BASE_COMMUNE = 'GH_main';

date_default_timezone_set('Europe/Paris');
mb_internal_encoding('UTF-8');

// Chargement automatique des classes BarberPay\Xxx → app/src/Xxx.php
spl_autoload_register(static function (string $classe): void {
    $prefixe = __NAMESPACE__ . '\\';
    if (strncmp($classe, $prefixe, strlen($prefixe)) !== 0) {
        return;
    }
    $relatif = str_replace('\\', '/', substr($classe, strlen($prefixe)));
    $fichier = DOSSIER_APP . '/src/' . $relatif . '.php';
    if (is_file($fichier)) {
        require $fichier;
    }
});

// Les erreurs mysqli deviennent des exceptions : aucune requête ne peut échouer en silence.
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/** Échappe une valeur pour l'afficher dans du HTML. */
function e(?string $texte): string
{
    return htmlspecialchars((string) $texte, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
