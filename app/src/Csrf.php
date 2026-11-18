<?php
declare(strict_types=1);

namespace BarberPay;

/** Jeton anti-falsification de requête, un par session. À vérifier sur toute action POST. */
final class Csrf
{
    private const CLE = 'csrf';

    public static function jeton(): string
    {
        if (empty($_SESSION[self::CLE])) {
            $_SESSION[self::CLE] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION[self::CLE];
    }

    public static function champ(): string
    {
        return '<input type="hidden" name="csrf" value="' . e(self::jeton()) . '">';
    }

    public static function valide(?string $jeton): bool
    {
        return is_string($jeton) && $jeton !== '' && hash_equals(self::jeton(), $jeton);
    }
}
