<?php
declare(strict_types=1);

namespace BarberPay;

use mysqli;
use RuntimeException;

/**
 * Chaînage des enregistrements fiscaux.
 *
 * empreinte = SHA-256( empreinte précédente + "\n" + JSON canonique de l'enregistrement )
 *
 * Le JSON canonique est construit à partir des mêmes valeurs que celles écrites en base, dans un ordre fixe :
 * le contrôle d'intégrité relit la base, reconstruit le JSON et doit retrouver exactement la même empreinte.
 */
final class Chaine
{
    public static function empreinte(string $precedente, array $canonique): string
    {
        return hash('sha256', $precedente . "\n" . self::json($canonique));
    }

    public static function json(array $donnees): string
    {
        return json_encode($donnees, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * Réserve le numéro suivant d'une chaîne. À appeler DANS une transaction : la ligne du compteur reste
     * verrouillée jusqu'au commit, ce qui sérialise les enregistrements.
     *
     * @return array{numero: int, precedente: string}
     */
    public static function reserver(mysqli $con, string $compteur): array
    {
        $requete = $con->prepare('SELECT valeur, dernier_hash FROM compteurs WHERE nom = ? FOR UPDATE');
        $requete->bind_param('s', $compteur);
        $requete->execute();
        $ligne = $requete->get_result()->fetch_assoc();
        if (!$ligne) {
            throw new RuntimeException('Compteur « ' . $compteur . ' » absent.');
        }
        return ['numero' => (int) $ligne['valeur'] + 1, 'precedente' => (string) $ligne['dernier_hash']];
    }

    public static function avancer(mysqli $con, string $compteur, int $numero, string $empreinte): void
    {
        $requete = $con->prepare('UPDATE compteurs SET valeur = ?, dernier_hash = ? WHERE nom = ?');
        $requete->bind_param('iss', $numero, $empreinte, $compteur);
        $requete->execute();
    }
}
