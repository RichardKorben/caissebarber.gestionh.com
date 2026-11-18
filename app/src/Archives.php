<?php
declare(strict_types=1);

namespace BarberPay;

use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use mysqli;

/**
 * Archives fiscales : une archive ZIP par clôture mensuelle ou annuelle, créée une seule fois, rangée hors du site
 * (/var/kdSite/barberpay/archives/<sous-domaine>/), avec son empreinte SHA-256 signée en base.
 * Toute modification du fichier est détectée (empreinte ou signature).
 */
final class Archives
{
    public const DOSSIER = '/var/kdSite/barberpay/archives';

    public function __construct(
        private readonly mysqli $con,
        private readonly Tenant $tenant,
        private readonly Journal $journal,
    ) {
    }

    /** @return array<string, mixed> l'archive de la clôture (créée si besoin) */
    public function pour(int $clotureId, ?int $operateurId): array
    {
        $existante = $this->parCloture($clotureId);
        if ($existante !== null) {
            return $existante;
        }
        $cloture = (new Clotures($this->con, $this->tenant, $this->journal))->charger($clotureId);
        if ($cloture === null || !in_array($cloture['type'], ['mois', 'annee'], true)) {
            throw new InvalidArgumentException('Archive disponible seulement pour une clôture mensuelle ou annuelle.');
        }
        [$debut, $fin] = $cloture['type'] === 'mois'
            ? [$cloture['periode'] . '-01', (new DateTimeImmutable($cloture['periode'] . '-01'))->modify('first day of next month')->format('Y-m-d')]
            : [$cloture['periode'] . '-01-01', ((int) $cloture['periode'] + 1) . '-01-01'];
        $titre = $cloture['type'] === 'mois' ? 'Archive mensuelle ' . $cloture['periode'] : 'Archive annuelle ' . $cloture['periode'];

        $dossier = self::DOSSIER . '/' . $this->tenant->sousDomaine;
        if (!is_dir($dossier) && !@mkdir($dossier, 02770) && !is_dir($dossier)) {
            throw new RuntimeException('Impossible de créer le dossier des archives.');
        }
        $nom = $cloture['type'] . '-' . $cloture['periode'] . '.zip';
        $temporaire = (new Exports($this->con, $this->tenant))->zipFiscal($debut, $fin, $titre);
        $destination = $dossier . '/' . $nom;
        if (!@rename($temporaire, $destination)) {
            @unlink($temporaire);
            throw new RuntimeException('Impossible d\'enregistrer l\'archive.');
        }
        @chmod($destination, 0640);
        $empreinte = (string) hash_file('sha256', $destination);
        $signature = Signature::pour($this->tenant)->signer($empreinte);
        $taille = (int) filesize($destination);
        $type = (string) $cloture['type'];
        $periode = (string) $cloture['periode'];

        $this->con->begin_transaction();
        $requete = $this->con->prepare(
            'INSERT INTO archives (cloture_id, type, periode, fichier, taille, empreinte, signature, operateur_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $requete->bind_param('isssissi', $clotureId, $type, $periode, $nom, $taille, $empreinte, $signature, $operateurId);
        $requete->execute();
        $this->journal->ecrire(Journal::ARCHIVE, ['type' => $type, 'periode' => $periode, 'empreinte' => $empreinte], $operateurId, null, true);
        $this->con->commit();
        return $this->parCloture($clotureId);
    }

    /** Chemin du fichier, après vérification de son empreinte et de sa signature. */
    public function fichierVerifie(array $archive): string
    {
        $chemin = self::DOSSIER . '/' . $this->tenant->sousDomaine . '/' . basename((string) $archive['fichier']);
        if (!$this->verifier($archive)) {
            throw new RuntimeException('L\'archive ' . $archive['fichier'] . ' a été modifiée ou est introuvable : téléchargement refusé.');
        }
        return $chemin;
    }

    public function verifier(array $archive): bool
    {
        $chemin = self::DOSSIER . '/' . $this->tenant->sousDomaine . '/' . basename((string) $archive['fichier']);
        return is_file($chemin)
            && hash_file('sha256', $chemin) === $archive['empreinte']
            && Signature::pour($this->tenant)->verifier((string) $archive['empreinte'], (string) $archive['signature']);
    }

    /** @return list<array<string, mixed>> clôtures mensuelles et annuelles, avec leur archive éventuelle */
    public function lister(): array
    {
        return $this->con->query(
            "SELECT c.id cloture_id, c.type, c.periode, c.net_ttc, a.id, a.fichier, a.taille, a.empreinte, a.signature, a.cree_le
             FROM clotures c LEFT JOIN archives a ON a.cloture_id = c.id
             WHERE c.type IN ('mois', 'annee') ORDER BY c.periode DESC, c.type"
        )->fetch_all(MYSQLI_ASSOC);
    }

    /** @return array<string, mixed>|null */
    private function parCloture(int $clotureId): ?array
    {
        $requete = $this->con->prepare('SELECT * FROM archives WHERE cloture_id = ?');
        $requete->bind_param('i', $clotureId);
        $requete->execute();
        return $requete->get_result()->fetch_assoc() ?: null;
    }
}
