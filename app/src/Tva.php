<?php
declare(strict_types=1);

namespace BarberPay;

/** Taux de TVA français, en centièmes de % (2000 = 20 %). */
final class Tva
{
    public const TAUX = [
        2000 => '20 %',
        1000 => '10 %',
        550 => '5,5 %',
        210 => '2,1 %',
        0 => '0 %',
    ];

    /** Taux normal : coiffure, barbe, produits cosmétiques. */
    public const DEFAUT = 2000;

    public static function valide(int $taux): bool
    {
        return array_key_exists($taux, self::TAUX);
    }

    public static function libelle(int $taux): string
    {
        return self::TAUX[$taux] ?? (number_format($taux / 100, 2, ',', '') . ' %');
    }
}
