<?php
/**
 * BarberPay — chargement commun (web et ligne de commande).
 *
 * Ne fait aucune sortie et ne démarre pas de session : voir app/web.php pour les pages.
 */
declare(strict_types=1);

namespace BarberPay;

const VERSION = '0.5.2';
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

/**
 * Favicon, icônes d'application et (pour les écrans du logiciel) manifeste PWA — DESIGN.md §9.
 * $application = false pour les pages lues hors du poste (ticket en ligne, documents imprimables).
 */
function icones_tete(bool $application = true): string
{
    $html = '<link rel="icon" href="/public/img/favicon.svg" type="image/svg+xml">'
        . '<link rel="icon" href="/public/img/favicon-32.png" sizes="32x32" type="image/png">'
        . '<link rel="apple-touch-icon" href="/public/img/apple-touch-icon-180.png">';
    if ($application) {
        $html .= '<link rel="manifest" href="/public/manifest.webmanifest">'
            . '<meta name="theme-color" content="#0A0A0A">';
    }
    return $html;
}

/**
 * Emblème BarberPay (pièce dorée + poteau), décoratif. Avec $anime, un poteau dessiné en CSS
 * recouvre celui de l'image et ses rayures tournent (réglage du salon, coupé si l'appareil
 * demande moins d'animations).
 */
function embleme(bool $grand = false, bool $anime = false): string
{
    $taille = $grand ? 96 : 60;
    return '<span class="embleme' . ($grand ? ' embleme-grand' : '') . '">'
        . '<img src="/public/img/emblem.svg" alt="" width="' . $taille . '" height="' . $taille . '">'
        . ($anime ? '<span class="embleme-poteau poteau-anime"></span>' : '')
        . '</span>';
}

/** Logotype « BARBERPAY » : BARBER en blanc, PAY en or. */
function logotype(): string
{
    return '<span class="logotype">BARBER<span>PAY</span></span>';
}
