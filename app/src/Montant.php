<?php
declare(strict_types=1);

namespace BarberPay;

/** Montants en centimes entiers (jamais de nombres à virgule pour l'argent). */
final class Montant
{
    /** Plafond d'un prix unitaire : 99 999,99 €. */
    public const MAX = 9_999_999;

    /**
     * « 25 », « 25,5 », « 25.50 », « 1 250,00 € » → centimes. Null si illisible, négatif ou trop grand.
     */
    public static function analyser(string $saisie): ?int
    {
        $texte = str_replace(["\u{00A0}", "\u{202F}", ' ', '€'], '', trim($saisie));
        $texte = str_replace(',', '.', $texte);
        if (!preg_match('/^(\d{1,7})(?:\.(\d{1,2}))?$/', $texte, $m)) {
            return null;
        }
        $centimes = (int) $m[1] * 100 + (int) str_pad($m[2] ?? '0', 2, '0');
        return $centimes <= self::MAX ? $centimes : null;
    }

    /** 2550 → « 25,50 € » */
    public static function format(int $centimes): string
    {
        return number_format($centimes / 100, 2, ',', "\u{202F}") . "\u{00A0}€";
    }

    /** Prix des vignettes (DESIGN.md §5.5) : décimales seulement si nécessaires. 2500 → « 25 € », 2550 → « 25,50 € » */
    public static function formatCourt(int $centimes): string
    {
        return $centimes % 100 === 0
            ? number_format(intdiv($centimes, 100), 0, ',', "\u{202F}") . "\u{00A0}€"
            : self::format($centimes);
    }

    /** Valeur pour un champ de saisie : 2550 → « 25,50 » */
    public static function saisie(?int $centimes): string
    {
        return $centimes === null ? '' : number_format($centimes / 100, 2, ',', '');
    }
}
