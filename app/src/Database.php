<?php
declare(strict_types=1);

namespace BarberPay;

use mysqli;
use RuntimeException;

/**
 * Connexions MariaDB.
 *
 * Les identifiants sont ceux du framework kd (/var/kdSite/bddUser.txt et bddMdp.txt, chiffrés,
 * déchiffrés par decryptage() de /var/adminKdFast/globalFunctions.php).
 * À l'étape 6, chaque barber aura son propre utilisateur MariaDB limité à sa base.
 */
final class Database
{
    private const FICHIER_UTILISATEUR = '/var/kdSite/bddUser.txt';
    private const FICHIER_MOT_DE_PASSE = '/var/kdSite/bddMdp.txt';
    private const BIBLIOTHEQUE_KD = '/var/adminKdFast/globalFunctions.php';

    /** @var array{0:string,1:string}|null */
    private static ?array $identifiants = null;

    /** @var array<string, mysqli> */
    private static array $connexions = [];

    /** Base commune (registre des barbers). */
    public static function commune(): mysqli
    {
        return self::connexion(BASE_COMMUNE);
    }

    /** Base d'un barber. */
    public static function barber(string $base): mysqli
    {
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $base)) {
            throw new RuntimeException('Nom de base invalide.');
        }
        return self::connexion($base);
    }

    /** Connexion sans base sélectionnée (création de base en ligne de commande). */
    public static function serveur(): mysqli
    {
        return self::connexion('');
    }

    private static function connexion(string $base): mysqli
    {
        if (isset(self::$connexions[$base])) {
            return self::$connexions[$base];
        }
        [$utilisateur, $motDePasse] = self::identifiants();
        $con = mysqli_init();
        // Les colonnes numériques reviennent en int/float PHP (et donc en nombres JSON), pas en chaînes.
        $con->options(MYSQLI_OPT_INT_AND_FLOAT_NATIVE, 1);
        $con->real_connect('localhost', $utilisateur, $motDePasse, $base === '' ? null : $base);
        $con->set_charset('utf8mb4');
        $con->query("SET time_zone = '" . date('P') . "'");
        return self::$connexions[$base] = $con;
    }

    /** @return array{0:string,1:string} */
    private static function identifiants(): array
    {
        if (self::$identifiants !== null) {
            return self::$identifiants;
        }
        if (!function_exists('decryptage')) {
            // Inclus dans une fonction : ses variables globales (tables d'icônes, de mois…) restent locales.
            // Tampon de sortie : le fichier émet des sauts de ligne hors des balises PHP.
            ob_start();
            try {
                (static function (): void {
                    require_once self::BIBLIOTHEQUE_KD;
                })();
            } finally {
                ob_end_clean();
            }
        }
        $utilisateur = @file_get_contents(self::FICHIER_UTILISATEUR);
        $motDePasse = @file_get_contents(self::FICHIER_MOT_DE_PASSE);
        if ($utilisateur === false || $motDePasse === false) {
            throw new RuntimeException('Identifiants de base de données illisibles.');
        }
        return self::$identifiants = [
            (string) \decryptage(trim($utilisateur)),
            (string) \decryptage(trim($motDePasse)),
        ];
    }
}
