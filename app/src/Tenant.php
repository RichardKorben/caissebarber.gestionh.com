<?php
declare(strict_types=1);

namespace BarberPay;

use mysqli;

/**
 * Un barber (un salon) : reconnu par le début de l'adresse web, il a sa propre base de données.
 *
 * Exemple : barbermax.gestionh.com → sous-domaine « barbermax » → ligne du registre
 * GH_main.barberpay_comptes → base « BARBER_barbermax ».
 */
final class Tenant
{
    public const STATUT_PREPARATION = 'preparation';
    public const STATUT_ACTIF = 'actif';
    public const STATUT_GELE = 'gele';

    private function __construct(
        public readonly int $id,
        public readonly string $sousDomaine,
        public readonly string $base,
        public readonly string $nomSalon,
        public readonly string $statut,
        /** Compte de démonstration (barberdemo) : connexion automatique, actions sensibles bloquées. */
        public readonly bool $demo = false,
    ) {
    }

    /**
     * Extrait le sous-domaine d'une adresse (« BarberMax.gestionh.com:443 » → « barbermax »).
     * Renvoie null si l'adresse n'a pas une forme acceptable.
     */
    public static function sousDomaineDepuisHote(string $hote): ?string
    {
        $hote = strtolower(trim($hote));
        $hote = preg_replace('/:\d+$/', '', $hote) ?? '';
        if (!preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)+$/', $hote)) {
            return null;
        }
        $premier = explode('.', $hote, 2)[0];
        return self::sousDomaineValide($premier) ? $premier : null;
    }

    /** 3 à 30 caractères : lettres minuscules, chiffres et tirets (pas au début ni à la fin). */
    public static function sousDomaineValide(string $sousDomaine): bool
    {
        return (bool) preg_match('/^[a-z0-9](?:[a-z0-9-]{1,28})[a-z0-9]$/', $sousDomaine);
    }

    public static function trouver(mysqli $commune, string $sousDomaine): ?self
    {
        $requete = $commune->prepare(
            'SELECT id, sous_domaine, base, nom_salon, statut, demo FROM barberpay_comptes WHERE sous_domaine = ? LIMIT 1'
        );
        $requete->bind_param('s', $sousDomaine);
        $requete->execute();
        $ligne = $requete->get_result()->fetch_assoc();
        return $ligne ? self::depuisLigne($ligne) : null;
    }

    /** @return list<self> */
    public static function tous(mysqli $commune): array
    {
        $comptes = [];
        foreach ($commune->query('SELECT id, sous_domaine, base, nom_salon, statut, demo FROM barberpay_comptes ORDER BY id') as $ligne) {
            $comptes[] = self::depuisLigne($ligne);
        }
        return $comptes;
    }

    public function estActif(): bool
    {
        return $this->statut === self::STATUT_ACTIF;
    }

    /** @param array<string, mixed> $ligne */
    private static function depuisLigne(array $ligne): self
    {
        return new self(
            (int) $ligne['id'],
            (string) $ligne['sous_domaine'],
            (string) $ligne['base'],
            (string) $ligne['nom_salon'],
            (string) $ligne['statut'],
            !empty($ligne['demo']),
        );
    }
}
