<?php
declare(strict_types=1);

namespace BarberPay;

/**
 * Date et heure du module fiscal. En production : l'heure du serveur (Europe/Paris).
 * Les tests peuvent la fixer pour simuler une journée, un changement de mois ou d'année.
 */
final class Horloge
{
    private static ?int $fixee = null;

    public static function date(string $format): string
    {
        return date($format, self::$fixee ?? time());
    }

    /** Fixe l'heure (« 2024-12-31 18:00:00 ») ; null pour revenir à l'heure réelle. Réservé aux tests. */
    public static function fixer(?string $dateHeure): void
    {
        if (PHP_SAPI !== 'cli') {
            return; // jamais sur le site
        }
        self::$fixee = $dateHeure === null ? null : (int) strtotime($dateHeure);
    }
}
