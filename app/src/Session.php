<?php
declare(strict_types=1);

namespace BarberPay;

/**
 * Session PHP sécurisée, propre à un barber.
 *
 * Le cookie est limité au sous-domaine (pas d'attribut Domain) : la session de barbermax.gestionh.com
 * n'est jamais envoyée à un autre barber. On vérifie en plus que la session appartient bien au barber
 * de l'adresse en cours.
 */
final class Session
{
    private const NOM = 'BARBERPAY';
    /** Déconnexion après 12 h d'inactivité (une journée de salon). Le verrouillage par PIN viendra à l'étape 2. */
    private const INACTIVITE_MAX = 12 * 3600;

    public static function demarrer(Tenant $tenant): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_name(self::NOM);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_start();

        $maintenant = time();
        $autreBarber = isset($_SESSION['tenant']) && $_SESSION['tenant'] !== $tenant->sousDomaine;
        $expiree = isset($_SESSION['vu_le']) && $maintenant - (int) $_SESSION['vu_le'] > self::INACTIVITE_MAX;
        if ($autreBarber || $expiree) {
            self::vider();
        }
        $_SESSION['tenant'] = $tenant->sousDomaine;
        $_SESSION['vu_le'] = $maintenant;
    }

    /** Nouvel identifiant de session (à la connexion), en gardant le barber. */
    public static function renouveler(): void
    {
        $tenant = $_SESSION['tenant'] ?? null;
        session_regenerate_id(true);
        $_SESSION = ['tenant' => $tenant, 'vu_le' => time()];
    }

    public static function vider(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
    }

    public static function detruire(): void
    {
        $_SESSION = [];
        $parametres = session_get_cookie_params();
        setcookie(self::NOM, '', [
            'expires' => time() - 3600,
            'path' => $parametres['path'],
            'secure' => $parametres['secure'],
            'httponly' => $parametres['httponly'],
            'samesite' => $parametres['samesite'],
        ]);
        session_destroy();
    }
}
