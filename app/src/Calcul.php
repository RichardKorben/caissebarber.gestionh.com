<?php
declare(strict_types=1);

namespace BarberPay;

use InvalidArgumentException;

/**
 * Calcul d'un ticket, en centimes entiers. Même algorithme que public/js/calcul.js (affichage en caisse) :
 * toute modification doit être faite des deux côtés, et les tests comparent les deux.
 *
 * 1. Ligne : brut = prix × quantité ; remise de ligne en % (arrondi au centime le plus proche) ou en €
 *    (plafonnée au brut) ; net = brut − remise.
 * 2. Sous-total = somme des nets. Remise sur le ticket en % ou en € (plafonnée au sous-total).
 * 3. La remise du ticket est répartie sur les lignes au prorata de leur net (méthode du plus grand reste :
 *    la somme des parts tombe juste au centime), pour connaître le TTC final de chaque taux de TVA.
 * 4. Par taux : HT = TTC / (1 + taux) arrondi au centime le plus proche ; TVA = TTC − HT.
 *
 * Remise en % : valeur en centièmes de % (1000 = 10 %, 10000 = 100 %). Total brut du ticket ≤ TOTAL_MAX.
 */
final class Calcul
{
    public const POURCENT = 'pct';
    public const EUROS = 'eur';
    /**
     * Plafond d'un ticket avant remises : 99 999,99 €. Il garantit que tous les produits intermédiaires
     * (remise × net) restent exacts, en PHP comme en JavaScript (sous 2^53).
     */
    public const TOTAL_MAX = 9_999_999;

    /**
     * @param list<array{prix:int, quantite:int, taux:int, remise?:array{type:string, valeur:int}|null}> $lignes
     * @param array{type:string, valeur:int}|null $remiseTicket
     * @return array{lignes: list<array{brut:int, remise:int, net:int, part_remise_ticket:int, final:int}>,
     *               sous_total:int, remise_ticket:int, total:int, nb_articles:int,
     *               tva: list<array{taux:int, ttc:int, ht:int, tva:int}>, total_ht:int, total_tva:int}
     */
    public static function ticket(array $lignes, ?array $remiseTicket = null): array
    {
        $resultats = [];
        $sousTotal = 0;
        $nbArticles = 0;
        foreach ($lignes as $ligne) {
            $prix = self::entier($ligne['prix'] ?? null, 0, Montant::MAX);
            $quantite = self::entier($ligne['quantite'] ?? null, 1, 999);
            $brut = $prix * $quantite;
            $remise = self::remise($brut, $ligne['remise'] ?? null);
            $net = $brut - $remise;
            $resultats[] = ['brut' => $brut, 'remise' => $remise, 'net' => $net, 'part_remise_ticket' => 0, 'final' => $net];
            $sousTotal += $net;
            $nbArticles += $quantite;
            $totalBrut = ($totalBrut ?? 0) + $brut;
            if ($totalBrut > self::TOTAL_MAX) {
                throw new InvalidArgumentException('Ticket trop élevé (99 999,99 € au maximum).');
            }
        }

        $remiseGlobale = self::remise($sousTotal, $remiseTicket);
        if ($remiseGlobale > 0 && $sousTotal > 0) {
            $parts = [];
            $restes = [];
            $distribue = 0;
            foreach ($resultats as $i => $r) {
                $numerateur = $remiseGlobale * $r['net'];
                $parts[$i] = intdiv($numerateur, $sousTotal);
                $restes[$i] = $numerateur % $sousTotal;
                $distribue += $parts[$i];
            }
            // Centimes restants : aux lignes dont la part tronquée a perdu le plus (à égalité, la première).
            $ordre = array_keys($restes);
            usort($ordre, static fn (int $a, int $b): int => $restes[$b] <=> $restes[$a] ?: $a <=> $b);
            for ($k = 0, $manque = $remiseGlobale - $distribue; $k < $manque; $k++) {
                $parts[$ordre[$k]]++;
            }
            foreach ($resultats as $i => &$r) {
                $r['part_remise_ticket'] = $parts[$i];
                $r['final'] = $r['net'] - $parts[$i];
            }
            unset($r);
        }

        $parTaux = [];
        foreach ($lignes as $i => $ligne) {
            $taux = self::entier($ligne['taux'] ?? null, 0, 10000);
            $parTaux[$taux] = ($parTaux[$taux] ?? 0) + $resultats[$i]['final'];
        }
        krsort($parTaux);
        $tva = [];
        $totalHt = 0;
        $totalTva = 0;
        foreach ($parTaux as $taux => $ttc) {
            $ht = self::htDepuisTtc($ttc, $taux);
            $tva[] = ['taux' => $taux, 'ttc' => $ttc, 'ht' => $ht, 'tva' => $ttc - $ht];
            $totalHt += $ht;
            $totalTva += $ttc - $ht;
        }

        return [
            'lignes' => $resultats,
            'sous_total' => $sousTotal,
            'remise_ticket' => $remiseGlobale,
            'total' => $sousTotal - $remiseGlobale,
            'nb_articles' => $nbArticles,
            'tva' => $tva,
            'total_ht' => $totalHt,
            'total_tva' => $totalTva,
        ];
    }

    /** HT = TTC × 10000 / (10000 + taux), arrondi au centime le plus proche (demi vers le haut). */
    public static function htDepuisTtc(int $ttc, int $taux): int
    {
        $diviseur = 10000 + $taux;
        return intdiv(2 * $ttc * 10000 + $diviseur, 2 * $diviseur);
    }

    /** @param array{type?:string, valeur?:int}|null $remise */
    public static function remise(int $base, ?array $remise): int
    {
        if ($remise === null || $base <= 0) {
            return 0;
        }
        $type = $remise['type'] ?? '';
        if ($type === self::POURCENT) {
            $pourcent = self::entier($remise['valeur'] ?? null, 0, 10000);
            return intdiv($base * $pourcent + 5000, 10000);
        }
        if ($type === self::EUROS) {
            return min($base, self::entier($remise['valeur'] ?? null, 0, PHP_INT_MAX));
        }
        throw new InvalidArgumentException('Type de remise inconnu.');
    }

    private static function entier(mixed $valeur, int $min, int $max): int
    {
        if (!is_int($valeur) || $valeur < $min || $valeur > $max) {
            throw new InvalidArgumentException('Valeur hors limites.');
        }
        return $valeur;
    }
}
