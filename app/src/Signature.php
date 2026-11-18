<?php
declare(strict_types=1);

namespace BarberPay;

use RuntimeException;

/**
 * Signature Ed25519 (extension sodium) des empreintes de tickets, du journal et des clôtures.
 *
 * Une paire de clés par barber, créée au premier besoin dans /var/kdSite/barberpay/cles/<sous-domaine>.cle
 * (hors du dossier web et hors de la base : quelqu'un qui modifie la base ne peut pas re-signer).
 * La clé publique est recopiée dans le registre (GH_main.barberpay_comptes.cle_publique) et dans les exports,
 * pour que n'importe qui puisse vérifier les signatures.
 */
final class Signature
{
    public const DOSSIER = '/var/kdSite/barberpay/cles';

    /** @var array<string, self> */
    private static array $instances = [];

    private function __construct(private readonly string $secrete, private readonly string $publique)
    {
    }

    public static function pour(Tenant $tenant): self
    {
        if (isset(self::$instances[$tenant->sousDomaine])) {
            return self::$instances[$tenant->sousDomaine];
        }
        $fichier = self::DOSSIER . '/' . $tenant->sousDomaine . '.cle';
        if (!is_file($fichier)) {
            self::creer($tenant, $fichier);
        }
        $contenu = base64_decode(trim((string) @file_get_contents($fichier)), true);
        if ($contenu === false || strlen($contenu) !== SODIUM_CRYPTO_SIGN_KEYPAIRBYTES) {
            throw new RuntimeException('Clé de signature illisible pour ' . $tenant->sousDomaine . '.');
        }
        return self::$instances[$tenant->sousDomaine] = new self(
            sodium_crypto_sign_secretkey($contenu),
            sodium_crypto_sign_publickey($contenu),
        );
    }

    /** Signature (base64) d'une empreinte. */
    public function signer(string $empreinte): string
    {
        return base64_encode(sodium_crypto_sign_detached($empreinte, $this->secrete));
    }

    public function verifier(string $empreinte, string $signature): bool
    {
        $brute = base64_decode($signature, true);
        return $brute !== false && strlen($brute) === SODIUM_CRYPTO_SIGN_BYTES
            && sodium_crypto_sign_verify_detached($brute, $empreinte, $this->publique);
    }

    public function clePublique(): string
    {
        return base64_encode($this->publique);
    }

    private static function creer(Tenant $tenant, string $fichier): void
    {
        $paire = sodium_crypto_sign_keypair();
        // Création exclusive (« x ») : si deux requêtes arrivent en même temps, une seule clé est gardée.
        $flux = @fopen($fichier, 'x');
        if ($flux === false) {
            if (is_file($fichier)) {
                return;
            }
            throw new RuntimeException('Impossible de créer la clé de signature (' . self::DOSSIER . ').');
        }
        fwrite($flux, base64_encode($paire) . "\n");
        fclose($flux);
        @chmod($fichier, 0640);
        $publique = base64_encode(sodium_crypto_sign_publickey($paire));
        sodium_memzero($paire);
        $requete = Database::commune()->prepare('UPDATE barberpay_comptes SET cle_publique = ? WHERE id = ?');
        $id = $tenant->id;
        $requete->bind_param('si', $publique, $id);
        $requete->execute();
    }
}
