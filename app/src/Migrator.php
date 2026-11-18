<?php
declare(strict_types=1);

namespace BarberPay;

use mysqli;
use RuntimeException;
use Throwable;

/**
 * Applique les fichiers SQL numérotés d'un dossier (0001_xxx.sql, 0002_xxx.sql…) à une base,
 * dans l'ordre, une seule fois chacun. Les versions appliquées sont notées dans une table de suivi.
 *
 * Attention : dans MariaDB, les CREATE / ALTER ne sont pas annulables. Si un fichier échoue en cours
 * de route, on s'arrête, on signale l'erreur, et le fichier devra être corrigé pour pouvoir être rejoué
 * (écrire les migrations avec IF NOT EXISTS quand c'est possible).
 */
final class Migrator
{
    public const DOSSIER_BARBER = DOSSIER_APP . '/migrations/tenant';
    public const DOSSIER_COMMUN = DOSSIER_APP . '/migrations/main';
    public const TABLE_BARBER = 'schema_version';
    public const TABLE_COMMUNE = 'barberpay_schema_version';

    public function __construct(
        private readonly mysqli $con,
        private readonly string $dossier,
        private readonly string $table,
    ) {
        if (!preg_match('/^[a-z_]+$/', $table)) {
            throw new RuntimeException('Nom de table de suivi invalide.');
        }
    }

    /**
     * @return list<string> fichiers appliqués lors de cet appel
     */
    public function migrer(): array
    {
        $verrou = 'barberpay_migration_' . $this->con->query('SELECT DATABASE()')->fetch_row()[0];
        $obtenu = (int) $this->requeteValeur('SELECT GET_LOCK(?, 30)', $verrou);
        if ($obtenu !== 1) {
            throw new RuntimeException('Une autre migration est en cours sur cette base.');
        }
        try {
            $this->creerTableSuivi();
            $dejaFaites = $this->versionsAppliquees();
            $appliquees = [];
            foreach ($this->fichiers() as $version => $fichier) {
                if (isset($dejaFaites[$version])) {
                    if ($dejaFaites[$version] !== hash_file('sha256', $fichier)) {
                        throw new RuntimeException(basename($fichier) . ' a été modifié après avoir été appliqué. Créez une nouvelle migration à la place.');
                    }
                    continue;
                }
                $this->appliquer($version, $fichier);
                $appliquees[] = basename($fichier);
            }
            return $appliquees;
        } finally {
            $this->requeteValeur('SELECT RELEASE_LOCK(?)', $verrou);
        }
    }

    public function versionActuelle(): int
    {
        $this->creerTableSuivi();
        return (int) $this->con->query("SELECT COALESCE(MAX(version), 0) FROM `{$this->table}`")->fetch_row()[0];
    }

    /** @return array<int, string> version => chemin, triés */
    private function fichiers(): array
    {
        $fichiers = [];
        foreach (glob($this->dossier . '/*.sql') ?: [] as $chemin) {
            if (!preg_match('/^(\d{4})_[a-z0-9_]+\.sql$/', basename($chemin), $m)) {
                throw new RuntimeException('Nom de migration invalide : ' . basename($chemin));
            }
            $version = (int) $m[1];
            if (isset($fichiers[$version])) {
                throw new RuntimeException('Deux migrations portent le numéro ' . $m[1] . '.');
            }
            $fichiers[$version] = $chemin;
        }
        ksort($fichiers);
        return $fichiers;
    }

    private function appliquer(int $version, string $fichier): void
    {
        $sql = (string) file_get_contents($fichier);
        try {
            // multi_query transmet le fichier entier au serveur, qui sait découper les blocs BEGIN … END
            // (triggers) sans DELIMITER.
            $this->con->multi_query($sql);
            do {
                if ($resultat = $this->con->store_result()) {
                    $resultat->free();
                }
            } while ($this->con->more_results() && $this->con->next_result());
        } catch (Throwable $erreur) {
            // Vider les résultats restants pour garder la connexion utilisable.
            while ($this->con->more_results()) {
                try { $this->con->next_result(); } catch (Throwable) { break; }
            }
            throw new RuntimeException(basename($fichier) . ' : ' . $erreur->getMessage(), 0, $erreur);
        }

        $nom = basename($fichier);
        $empreinte = hash_file('sha256', $fichier);
        $requete = $this->con->prepare("INSERT INTO `{$this->table}` (version, fichier, empreinte, applique_le) VALUES (?, ?, ?, NOW())");
        $requete->bind_param('iss', $version, $nom, $empreinte);
        $requete->execute();
    }

    private function creerTableSuivi(): void
    {
        $this->con->query(
            "CREATE TABLE IF NOT EXISTS `{$this->table}` (
                version INT UNSIGNED NOT NULL PRIMARY KEY,
                fichier VARCHAR(190) NOT NULL,
                empreinte CHAR(64) NOT NULL,
                applique_le DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    /** @return array<int, string> version => empreinte */
    private function versionsAppliquees(): array
    {
        $versions = [];
        foreach ($this->con->query("SELECT version, empreinte FROM `{$this->table}`") as $ligne) {
            $versions[(int) $ligne['version']] = (string) $ligne['empreinte'];
        }
        return $versions;
    }

    private function requeteValeur(string $sql, string $parametre): mixed
    {
        $requete = $this->con->prepare($sql);
        $requete->bind_param('s', $parametre);
        $requete->execute();
        return $requete->get_result()->fetch_row()[0] ?? null;
    }
}
