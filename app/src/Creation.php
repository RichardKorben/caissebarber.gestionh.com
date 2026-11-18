<?php
declare(strict_types=1);

namespace BarberPay;

use mysqli;
use RuntimeException;
use Throwable;

/**
 * Création d'un salon complet pour l'inscription en ligne (étape 6) : base, registre, migrations, gérant,
 * paramètres du salon et catalogue de départ. Appelée par `barberpay compte:creer` (JSON sur l'entrée standard),
 * elle-même lancée par le script root /var/kdSite/scriptsV2/barber_creation.py, qui s'occupe ensuite d'Apache et
 * du certificat. Le compte reste « en préparation » (page d'attente) jusqu'à ce que l'adresse HTTPS fonctionne.
 *
 * En cas d'échec, tout ce qui a été créé ici est supprimé (base et ligne du registre).
 */
final class Creation
{
    public const PREFIXE_BASE = 'BARBER_';
    /** Sous-domaines créés en ligne : plus strict que Tenant::sousDomaineValide (pas de tiret, commence par une lettre). */
    public const SOUS_DOMAINE_RE = '/^[a-z][a-z0-9]{2,29}$/';

    /**
     * Prestations proposées au départ : masquées et à 0 €, le gérant met ses prix puis les active.
     * @var list<array{0: string, 1: int}> nom, durée en minutes
     */
    public const CATALOGUE_DEPART = [
        ['Coupe homme', 30],
        ['Dégradé', 35],
        ['Barbe', 20],
        ['Coupe + barbe', 45],
        ['Coupe enfant', 20],
    ];

    public function __construct(private readonly mysqli $commune, private readonly mysqli $serveur)
    {
    }

    /**
     * @param array<string, mixed> $d sous_domaine, nom_salon, raison_sociale, adresse, code_postal, ville, siret,
     *                                regime_tva, prenom, nom, email, telephone, mot_de_passe
     * @return array{base: string, compte: int}
     */
    public function creer(array $d): array
    {
        $v = $this->valider($d);
        $base = self::PREFIXE_BASE . $v['sous_domaine'];

        $existe = $this->serveur->prepare('SELECT 1 FROM information_schema.schemata WHERE schema_name = ?');
        $existe->bind_param('s', $base);
        $existe->execute();
        if ($existe->get_result()->fetch_row()) {
            throw new RuntimeException("La base {$base} existe déjà.");
        }
        $this->verifierLibre($v['sous_domaine']);

        $compteId = null;
        $baseCreee = false;
        try {
            $this->serveur->query("CREATE DATABASE `{$base}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $baseCreee = true;

            $statut = Tenant::STATUT_PREPARATION;
            $ajout = $this->commune->prepare(
                'INSERT INTO barberpay_comptes (sous_domaine, base, nom_salon, email_gerant, statut) VALUES (?, ?, ?, ?, ?)'
            );
            $ajout->bind_param('sssss', $v['sous_domaine'], $base, $v['nom_salon'], $v['email'], $statut);
            $ajout->execute();
            $compteId = (int) $this->commune->insert_id;

            $con = Database::barber($base);
            $migrator = new Migrator($con, Migrator::DOSSIER_BARBER, Migrator::TABLE_BARBER);
            $migrator->migrer();
            $version = $migrator->versionActuelle();
            $maj = $this->commune->prepare('UPDATE barberpay_comptes SET version_schema = ? WHERE id = ?');
            $maj->bind_param('ii', $version, $compteId);
            $maj->execute();

            $this->remplir($con, $v);
        } catch (Throwable $erreur) {
            $this->defaire($compteId, $baseCreee ? $base : null);
            throw $erreur;
        }
        return ['base' => $base, 'compte' => $compteId];
    }

    /**
     * Supprime un compte resté « en préparation » (création interrompue). Refuse un compte actif ou gelé :
     * ses données de caisse doivent être conservées.
     */
    public function annuler(string $sousDomaine): bool
    {
        $requete = $this->commune->prepare('SELECT id, base, statut FROM barberpay_comptes WHERE sous_domaine = ?');
        $requete->bind_param('s', $sousDomaine);
        $requete->execute();
        $ligne = $requete->get_result()->fetch_assoc();
        if (!$ligne) {
            return false;
        }
        if ($ligne['statut'] !== Tenant::STATUT_PREPARATION) {
            throw new RuntimeException("Le compte « {$sousDomaine} » n'est pas en préparation : rien n'est supprimé.");
        }
        $this->defaire((int) $ligne['id'], (string) $ligne['base']);
        return true;
    }

    /** Met le compte en service (appelé quand l'adresse HTTPS répond). */
    public function activer(string $sousDomaine): void
    {
        $actif = Tenant::STATUT_ACTIF;
        $preparation = Tenant::STATUT_PREPARATION;
        $maj = $this->commune->prepare('UPDATE barberpay_comptes SET statut = ? WHERE sous_domaine = ? AND statut = ?');
        $maj->bind_param('sss', $actif, $sousDomaine, $preparation);
        $maj->execute();
        if ($maj->affected_rows !== 1) {
            throw new RuntimeException("Le compte « {$sousDomaine} » n'est pas en préparation.");
        }
    }

    /** Numéro de TVA intracommunautaire français calculé à partir du SIREN. */
    public static function tvaIntracom(string $siren): string
    {
        return sprintf('FR%02d%s', (12 + 3 * ((int) $siren % 97)) % 97, $siren);
    }

    /**
     * @param array<string, mixed> $d
     * @return array<string, ?string>
     */
    private function valider(array $d): array
    {
        $t = static fn (string $cle, int $max): ?string =>
            ($x = trim((string) ($d[$cle] ?? ''))) === '' ? null : mb_substr($x, 0, $max);
        $v = [
            'sous_domaine' => strtolower((string) $t('sous_domaine', 30)),
            'nom_salon' => $t('nom_salon', 120),
            'raison_sociale' => $t('raison_sociale', 160),
            'adresse' => $t('adresse', 190),
            'code_postal' => $t('code_postal', 10),
            'ville' => $t('ville', 100),
            'siret' => preg_replace('/\D/', '', (string) ($d['siret'] ?? '')),
            'regime_tva' => (string) ($d['regime_tva'] ?? ''),
            'prenom' => $t('prenom', 60),
            'nom' => $t('nom', 60),
            'email' => mb_strtolower((string) $t('email', 190)),
            'telephone' => $t('telephone', 30),
            'mot_de_passe' => (string) ($d['mot_de_passe'] ?? ''),
        ];
        if (!preg_match(self::SOUS_DOMAINE_RE, $v['sous_domaine'])) {
            throw new RuntimeException('Sous-domaine invalide.');
        }
        if ($v['nom_salon'] === null || $v['prenom'] === null) {
            throw new RuntimeException('Nom du salon et prénom obligatoires.');
        }
        if (!filter_var($v['email'], FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('E-mail invalide.');
        }
        if (!preg_match('/^\d{14}$/', (string) $v['siret'])) {
            throw new RuntimeException('SIRET invalide.');
        }
        if (!in_array($v['regime_tva'], [Parametres::FRANCHISE, Parametres::ASSUJETTI], true)) {
            throw new RuntimeException('Régime de TVA invalide.');
        }
        if (mb_strlen($v['mot_de_passe']) < 10) {
            throw new RuntimeException('Mot de passe trop court.');
        }
        return $v;
    }

    private function verifierLibre(string $sousDomaine): void
    {
        foreach (['SELECT 1 FROM barberpay_noms_reserves WHERE nom = ?', 'SELECT 1 FROM barberpay_comptes WHERE sous_domaine = ?'] as $sql) {
            $requete = $this->commune->prepare($sql);
            $requete->bind_param('s', $sousDomaine);
            $requete->execute();
            if ($requete->get_result()->fetch_row()) {
                throw new RuntimeException("Le sous-domaine « {$sousDomaine} » n'est pas libre.");
            }
        }
    }

    /** Gérant, paramètres du salon et catalogue de départ, dans la nouvelle base. */
    private function remplir(mysqli $con, array $v): void
    {
        $empreinte = password_hash($v['mot_de_passe'], PASSWORD_DEFAULT);
        $gerant = $con->prepare("INSERT INTO utilisateurs (prenom, nom, email, mot_de_passe, role) VALUES (?, ?, ?, ?, 'gerant')");
        $gerant->bind_param('ssss', $v['prenom'], $v['nom'], $v['email'], $empreinte);
        $gerant->execute();

        $tva = $v['regime_tva'] === Parametres::ASSUJETTI ? self::tvaIntracom(substr($v['siret'], 0, 9)) : null;
        $param = $con->prepare(
            'INSERT INTO parametres (id, nom_salon, raison_sociale, adresse, code_postal, ville, telephone, siret, tva_intracom, regime_tva)
             VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $param->bind_param('sssssssss', $v['nom_salon'], $v['raison_sociale'], $v['adresse'], $v['code_postal'],
            $v['ville'], $v['telephone'], $v['siret'], $tva, $v['regime_tva']);
        $param->execute();

        $article = $con->prepare(
            "INSERT INTO articles (type, nom, prix_ttc, taux_tva, duree_minutes, ordre, actif) VALUES ('service', ?, 0, 2000, ?, ?, 0)"
        );
        foreach (self::CATALOGUE_DEPART as $i => [$nom, $duree]) {
            $ordre = $i + 1;
            $article->bind_param('sii', $nom, $duree, $ordre);
            $article->execute();
        }
    }

    private function defaire(?int $compteId, ?string $base): void
    {
        if ($base !== null && str_starts_with($base, self::PREFIXE_BASE)) {
            $this->serveur->query("DROP DATABASE IF EXISTS `{$base}`");
        }
        if ($compteId !== null) {
            $suppression = $this->commune->prepare('DELETE FROM barberpay_comptes WHERE id = ? AND statut = ?');
            $preparation = Tenant::STATUT_PREPARATION;
            $suppression->bind_param('is', $compteId, $preparation);
            $suppression->execute();
        }
    }
}
