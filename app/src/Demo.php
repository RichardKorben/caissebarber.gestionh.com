<?php
declare(strict_types=1);

namespace BarberPay;

use mysqli;
use RuntimeException;

/**
 * Compte de démonstration https://barberdemo.gestionh.com (lien « Ouvrir la démo » de barberpay.fr).
 *
 * `barberpay demo:reinitialiser` (chaque nuit à 5 h, /etc/cron.d/barberpay-demo, en www-data) recrée entièrement
 * la base BARBER_barberdemo : un faux salon « Barber Démo » (assujetti, SIRET à zéros), 7 prestations et 4 produits
 * avec leurs prix, 3 barbiers avec un code PIN (affichés sur l'écran de verrouillage), et la semaine passée
 * (du mardi au samedi) : ouverture, ventes, une annulation, clôture Z chaque soir. La journée du jour démarre vide.
 * Les ventes sont fabriquées par le vrai moteur (Ventes, Clotures) avec l'horloge fixée : chaînage et signatures
 * sont donc valides (`barberpay integrite --compte=barberdemo`).
 *
 * Ne touche qu'à ce compte, et refuse d'agir si la ligne du registre existe sans l'indicateur demo = 1.
 */
final class Demo
{
    public const SOUS_DOMAINE = 'barberdemo';
    public const BASE = 'BARBER_barberdemo';
    public const NOM_SALON = 'Barber Démo';
    public const EMAIL = 'demo@barberpay.fr';
    public const MENTION_TICKET = 'Ticket de démonstration BarberPay — sans valeur';

    /** @var list<array{0: string, 1: string, 2: string}> prénom, rôle, code PIN */
    public const EQUIPE = [['Max', 'gerant', '1234'], ['Yanis', 'barbier', '1111'], ['Karim', 'barbier', '2222']];

    /** @var list<array{0: string, 1: int, 2: int, 3: int}> nom, prix TTC en centimes, durée, poids dans les ventes */
    public const SERVICES = [
        ['Coupe homme', 2600, 30, 30],
        ['Dégradé', 2800, 35, 22],
        ['Coupe + barbe', 3800, 45, 18],
        ['Taille de barbe', 1500, 20, 12],
        ['Rasage traditionnel', 2800, 30, 6],
        ['Coupe enfant (-12 ans)', 1800, 20, 8],
        ['Contours & finitions', 1000, 10, 4],
    ];

    /** @var list<array{0: string, 1: int, 2: int}> nom, prix TTC en centimes, stock de départ */
    public const PRODUITS = [
        ['Cire mate', 1490, 24],
        ['Huile à barbe', 2200, 18],
        ['Shampoing barbe', 1200, 15],
        ['Baume après-rasage', 1600, 12],
    ];

    public function __construct(private readonly mysqli $commune, private readonly mysqli $serveur)
    {
    }

    public static function astucePins(): string
    {
        return implode(' · ', array_map(static fn (array $m): string => $m[0] . ' ' . $m[2], self::EQUIPE));
    }

    /** @return array{tickets: int, jours: int, clotures: int} */
    public function reinitialiser(): array
    {
        $this->effacer();
        $tenant = $this->creer();
        $con = Database::barber(self::BASE);
        try {
            $stats = $this->semaine($con, $tenant);
        } finally {
            Horloge::fixer(null);
        }
        return $stats;
    }

    private function effacer(): void
    {
        $sd = self::SOUS_DOMAINE;
        $requete = $this->commune->prepare('SELECT demo FROM barberpay_comptes WHERE sous_domaine = ?');
        $requete->bind_param('s', $sd);
        $requete->execute();
        $ligne = $requete->get_result()->fetch_assoc();
        if ($ligne && (int) $ligne['demo'] !== 1) {
            throw new RuntimeException('Le compte « ' . $sd . ' » existe sans être marqué démo : rien n\'est effacé.');
        }
        $suppression = $this->commune->prepare('DELETE FROM barberpay_comptes WHERE sous_domaine = ? AND demo = 1');
        $suppression->bind_param('s', $sd);
        $suppression->execute();
        $this->serveur->query('DROP DATABASE IF EXISTS `' . self::BASE . '`');
        // Nouvelle base, nouvelle chaîne : nouvelle clé de signature et plus d'archives de l'ancienne.
        @unlink(Signature::DOSSIER . '/' . $sd . '.cle');
        $archives = Archives::DOSSIER . '/' . $sd;
        if (is_dir($archives)) {
            foreach ((array) glob($archives . '/*') as $fichier) {
                @unlink((string) $fichier);
            }
            @rmdir($archives);
        }
    }

    private function creer(): Tenant
    {
        $this->serveur->query('CREATE DATABASE `' . self::BASE . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $sd = self::SOUS_DOMAINE;
        $base = self::BASE;
        $nom = self::NOM_SALON;
        $email = self::EMAIL;
        $ajout = $this->commune->prepare(
            "INSERT INTO barberpay_comptes (sous_domaine, base, nom_salon, email_gerant, statut, demo) VALUES (?, ?, ?, ?, 'actif', 1)"
        );
        $ajout->bind_param('ssss', $sd, $base, $nom, $email);
        $ajout->execute();
        $id = (int) $this->commune->insert_id;

        $con = Database::barber($base);
        $migrator = new Migrator($con, Migrator::DOSSIER_BARBER, Migrator::TABLE_BARBER);
        $migrator->migrer();
        $version = $migrator->versionActuelle();
        $maj = $this->commune->prepare('UPDATE barberpay_comptes SET version_schema = ? WHERE id = ?');
        $maj->bind_param('ii', $version, $id);
        $maj->execute();

        $mention = self::MENTION_TICKET;
        $param = $con->prepare(
            "INSERT INTO parametres (id, nom_salon, raison_sociale, adresse, code_postal, ville, siret, tva_intracom, regime_tva,
                                     mention_ticket, verrouillage_minutes)
             VALUES (1, ?, 'Salon de démonstration (fictif)', '1 rue de la Démo', '75000', 'Paris', '00000000000000',
                     'FR00000000000', 'assujetti', ?, 0)"
        );
        $param->bind_param('ss', $nom, $mention);
        $param->execute();

        $membre = $con->prepare('INSERT INTO utilisateurs (id, prenom, role, email, mot_de_passe, pin, couleur) VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach (self::EQUIPE as $i => [$prenom, $role, $pin]) {
            $id = $i + 1;
            $mail = $role === Equipe::GERANT ? $email : null;
            // mot de passe inconnu de tous : on entre dans la démo par la connexion automatique
            $mdp = $role === Equipe::GERANT ? password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT) : null;
            $hashPin = password_hash($pin, PASSWORD_DEFAULT);
            $couleur = Equipe::COULEURS[$i % count(Equipe::COULEURS)];
            $membre->bind_param('issssss', $id, $prenom, $role, $mail, $mdp, $hashPin, $couleur);
            $membre->execute();
        }

        $article = $con->prepare("INSERT INTO articles (type, nom, prix_ttc, taux_tva, duree_minutes, stock, stock_alerte, ordre) VALUES (?, ?, ?, 2000, ?, ?, ?, ?)");
        $ordre = 0;
        foreach (self::SERVICES as [$nomArticle, $prix, $duree]) {
            $type = 'service';
            $stock = $alerte = null;
            $ordre++;
            $article->bind_param('ssiiiii', $type, $nomArticle, $prix, $duree, $stock, $alerte, $ordre);
            $article->execute();
        }
        foreach (self::PRODUITS as [$nomArticle, $prix, $stock]) {
            $type = 'produit';
            $duree = null;
            $alerte = 3;
            $ordre++;
            $article->bind_param('ssiiiii', $type, $nomArticle, $prix, $duree, $stock, $alerte, $ordre);
            $article->execute();
        }

        $tenant = Tenant::trouver($this->commune, $sd);
        if ($tenant === null) {
            throw new RuntimeException('Compte de démo introuvable après création.');
        }
        return $tenant;
    }

    /**
     * Du mardi au samedi des 7 derniers jours : ouverture à 9 h (150 € de fond), 6 à 11 tickets, clôture Z à 19 h 15.
     * Tirages reproductibles (graine = date), pour une démo identique d'une nuit à l'autre à date égale.
     *
     * @return array{tickets: int, jours: int, clotures: int}
     */
    private function semaine(mysqli $con, Tenant $tenant): array
    {
        $journal = new Journal($con, $tenant);
        $ventes = new Ventes($con, $tenant, $journal);
        $clotures = new Clotures($con, $tenant, $journal);
        $services = $con->query("SELECT id, nom, prix_ttc FROM articles WHERE type = 'service' ORDER BY ordre")->fetch_all(MYSQLI_ASSOC);
        $produits = $con->query("SELECT id, nom, prix_ttc FROM articles WHERE type = 'produit' ORDER BY ordre")->fetch_all(MYSQLI_ASSOC);
        $poids = array_column(self::SERVICES, 3);
        $tickets = $jours = $nbClotures = 0;
        $annulationFaite = false;

        for ($recul = 7; $recul >= 1; $recul--) {
            $jour = date('Y-m-d', strtotime("-{$recul} days"));
            $semaine = (int) date('N', strtotime($jour));        // 1 = lundi … 7 = dimanche
            if ($semaine === 1 || $semaine === 7) {
                continue;                                          // fermé le dimanche et le lundi
            }
            mt_srand(crc32($jour));
            $jours++;
            Horloge::fixer($jour . ' 08:55:00');
            $clotures->rattraper();
            Horloge::fixer($jour . ' 09:00:00');
            $clotures->ouvrir(15000, 1);

            $nb = mt_rand(6, 11);
            $minutes = [];
            for ($i = 0; $i < $nb; $i++) {
                $minutes[] = mt_rand(9 * 60 + 20, 18 * 60 + 40);
            }
            sort($minutes);
            $dernierTicket = null;
            foreach ($minutes as $m) {
                Horloge::fixer(sprintf('%s %02d:%02d:%02d', $jour, intdiv($m, 60), $m % 60, mt_rand(0, 59)));
                $barbier = mt_rand(1, 3);
                $service = $services[$this->tirage($poids)];
                $lignes = [$this->ligne($service, 'service', $barbier)];
                if (mt_rand(1, 100) <= 25) {
                    $produit = $produits[mt_rand(0, count($produits) - 1)];
                    $lignes[] = $this->ligne($produit, 'produit', $barbier);
                }
                $remise = mt_rand(1, 100) <= 8 ? ['type' => Calcul::POURCENT, 'valeur' => 1000, 'motif' => 'Fidélité'] : null;
                $total = Calcul::ticket($lignes, $remise)['total'];
                $vente = $ventes->encaisser(['barbierId' => $barbier, 'remise' => $remise, 'lignes' => $lignes],
                    $this->paiements($total), null, null, $barbier);
                $tickets++;
                $dernierTicket = $vente['id'];
            }
            // Une annulation dans la semaine (le premier jour d'ouverture), pour montrer le ticket négatif.
            if (!$annulationFaite && $dernierTicket !== null) {
                Horloge::fixer($jour . ' 18:55:00');
                $ventes->annuler((int) $dernierTicket, 1, 'Erreur de saisie');
                $annulationFaite = true;
            }
            Horloge::fixer($jour . ' 19:15:00');
            $attendues = $clotures->especesAttendues($jour);
            $clotures->cloturerJour($jour, 1, false, $jours === 2 ? $attendues - 200 : $attendues);
            $nbClotures++;
        }
        Horloge::fixer(null);
        $clotures->rattraper();
        return ['tickets' => $tickets, 'jours' => $jours, 'clotures' => $nbClotures];
    }

    /**
     * @param array{id: int|string, nom: string, prix_ttc: int|string} $article
     * @return array<string, mixed>
     */
    private function ligne(array $article, string $type, int $barbier): array
    {
        return ['articleId' => (int) $article['id'], 'type' => $type, 'nom' => (string) $article['nom'],
            'prix' => (int) $article['prix_ttc'], 'taux' => 2000, 'quantite' => 1, 'barbierId' => $barbier, 'remise' => null];
    }

    /** 60 % carte, 35 % espèces (billet rond, rendu de monnaie), 5 % carte + espèces. */
    private function paiements(int $total): array
    {
        $t = mt_rand(1, 100);
        if ($t <= 60) {
            return [['moyen' => 'cb', 'montant' => $total]];
        }
        if ($t <= 95) {
            $recu = $total;
            foreach ([1000, 2000, 5000, 10000] as $billet) {
                if ($billet >= $total) {
                    $recu = $billet;
                    break;
                }
            }
            return [['moyen' => 'especes', 'montant' => $total, 'recu' => $recu]];
        }
        $especes = min(2000, intdiv($total, 2));
        return [['moyen' => 'cb', 'montant' => $total - $especes], ['moyen' => 'especes', 'montant' => $especes, 'recu' => $especes]];
    }

    /** @param list<int> $poids */
    private function tirage(array $poids): int
    {
        $r = mt_rand(1, array_sum($poids));
        foreach ($poids as $i => $p) {
            $r -= $p;
            if ($r <= 0) {
                return $i;
            }
        }
        return 0;
    }
}
