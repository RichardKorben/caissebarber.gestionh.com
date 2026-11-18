<?php
/**
 * BarberPay — commandes d'administration. À lancer avec app/cli/barberpay (active les balises courtes
 * nécessaires à la bibliothèque kd).
 *
 *   barberpay migrer [--compte=<sous-domaine>]
 *   barberpay comptes
 *   barberpay compte:ajouter --sous-domaine=<x> --base=<BASE> --salon="<nom>" [--creer-base] [--statut=actif]
 *   barberpay integrite --compte=<sous-domaine>     contrôle des tickets et du journal (chaînage, signatures)
 *   barberpay cloturer --compte=<sous-domaine> | --tous   clôtures automatiques en retard (à lancer chaque nuit)
 *   barberpay gerant:creer --compte=<sous-domaine> --email=<e-mail> --prenom=<prénom> [--nom=<nom>]
 *       (mot de passe lu dans la variable d'environnement BP_MOT_DE_PASSE, sinon généré et affiché une fois)
 *   barberpay compte:creer < donnees.json          salon complet (inscription en ligne), réponse JSON
 *   barberpay compte:activer --sous-domaine=<x>    compte « en préparation » → actif, réponse JSON
 *   barberpay compte:annuler --sous-domaine=<x>    supprime un compte resté « en préparation », réponse JSON
 *   barberpay demo:reinitialiser                    recrée la démo barberdemo (chaque nuit, en www-data)
 */
declare(strict_types=1);

use BarberPay\Database;
use BarberPay\Migrator;
use BarberPay\Tenant;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../bootstrap.php';

/** @return array<string, string|true> */
function bp_options(array $arguments): array
{
    $options = [];
    foreach ($arguments as $argument) {
        if (preg_match('/^--([a-z-]+)(?:=(.*))?$/s', $argument, $m)) {
            $options[$m[1]] = array_key_exists(2, $m) ? $m[2] : true;
        }
    }
    return $options;
}

function bp_option(array $options, string $nom, bool $obligatoire = true): ?string
{
    $valeur = $options[$nom] ?? null;
    if ($valeur === null || $valeur === true || $valeur === '') {
        if ($obligatoire) {
            fwrite(STDERR, "Option manquante : --{$nom}=…\n");
            exit(2);
        }
        return null;
    }
    return (string) $valeur;
}

function bp_compte(string $sousDomaine): Tenant
{
    $tenant = Tenant::trouver(Database::commune(), $sousDomaine);
    if ($tenant === null) {
        fwrite(STDERR, "Aucun compte « {$sousDomaine} » dans le registre.\n");
        exit(1);
    }
    return $tenant;
}

function bp_migrer_compte(Tenant $tenant): bool
{
    try {
        $con = Database::barber($tenant->base);
        $migrator = new Migrator($con, Migrator::DOSSIER_BARBER, Migrator::TABLE_BARBER);
        $faites = $migrator->migrer();
        $version = $migrator->versionActuelle();
        $maj = Database::commune()->prepare('UPDATE barberpay_comptes SET version_schema = ? WHERE id = ?');
        $id = $tenant->id;
        $maj->bind_param('ii', $version, $id);
        $maj->execute();
        echo sprintf("  %-20s %-24s v%d %s\n", $tenant->sousDomaine, $tenant->base, $version,
            $faites ? '← ' . implode(', ', $faites) : '(à jour)');
        return true;
    } catch (Throwable $erreur) {
        echo sprintf("  %-20s %-24s ÉCHEC : %s\n", $tenant->sousDomaine, $tenant->base, $erreur->getMessage());
        return false;
    }
}

$commande = $argv[1] ?? 'aide';
$options = bp_options(array_slice($argv, 2));

try {
    switch ($commande) {
        case 'migrer':
            echo "Base commune " . BarberPay\BASE_COMMUNE . " :\n";
            $commune = new Migrator(Database::commune(), Migrator::DOSSIER_COMMUN, Migrator::TABLE_COMMUNE);
            $faites = $commune->migrer();
            echo '  v' . $commune->versionActuelle() . ' ' . ($faites ? '← ' . implode(', ', $faites) : '(à jour)') . "\n";

            $cible = bp_option($options, 'compte', false);
            $comptes = $cible !== null ? [bp_compte($cible)] : Tenant::tous(Database::commune());
            echo "Bases des barbers (" . count($comptes) . ") :\n";
            $echecs = 0;
            foreach ($comptes as $tenant) {
                $echecs += bp_migrer_compte($tenant) ? 0 : 1;
            }
            echo $echecs === 0 ? "Terminé.\n" : "Terminé avec {$echecs} échec(s).\n";
            exit($echecs === 0 ? 0 : 1);

        case 'comptes':
            foreach (Tenant::tous(Database::commune()) as $tenant) {
                echo sprintf("%-20s %-24s %-12s %s\n", $tenant->sousDomaine, $tenant->base, $tenant->statut, $tenant->nomSalon);
            }
            exit(0);

        case 'compte:ajouter':
            $sousDomaine = strtolower((string) bp_option($options, 'sous-domaine'));
            $base = (string) bp_option($options, 'base');
            $salon = (string) bp_option($options, 'salon');
            $statut = bp_option($options, 'statut', false) ?? Tenant::STATUT_ACTIF;
            if (!Tenant::sousDomaineValide($sousDomaine)) {
                throw new RuntimeException('Sous-domaine invalide (3 à 30 caractères : a-z, 0-9, tiret).');
            }
            if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $base)) {
                throw new RuntimeException('Nom de base invalide (A-Z, a-z, 0-9, _).');
            }
            if (!in_array($statut, [Tenant::STATUT_PREPARATION, Tenant::STATUT_ACTIF, Tenant::STATUT_GELE], true)) {
                throw new RuntimeException('Statut invalide.');
            }
            $reserve = Database::commune()->prepare('SELECT 1 FROM barberpay_noms_reserves WHERE nom = ?');
            $reserve->bind_param('s', $sousDomaine);
            $reserve->execute();
            if ($reserve->get_result()->fetch_row()) {
                throw new RuntimeException("« {$sousDomaine} » est un nom réservé.");
            }
            if (isset($options['creer-base'])) {
                Database::serveur()->query("CREATE DATABASE IF NOT EXISTS `{$base}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            }
            $ajout = Database::commune()->prepare(
                'INSERT INTO barberpay_comptes (sous_domaine, base, nom_salon, statut) VALUES (?, ?, ?, ?)'
            );
            $ajout->bind_param('ssss', $sousDomaine, $base, $salon, $statut);
            $ajout->execute();
            echo "Compte « {$sousDomaine} » ajouté (base {$base}, statut {$statut}).\n";
            $ok = bp_migrer_compte(bp_compte($sousDomaine));
            exit($ok ? 0 : 1);

        case 'gerant:creer':
            $tenant = bp_compte((string) bp_option($options, 'compte'));
            $email = mb_strtolower(trim((string) bp_option($options, 'email')));
            $prenom = trim((string) bp_option($options, 'prenom'));
            $nom = bp_option($options, 'nom', false);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('E-mail invalide.');
            }
            $motDePasse = (string) getenv('BP_MOT_DE_PASSE');
            $genere = $motDePasse === '';
            if ($genere) {
                $motDePasse = rtrim(strtr(base64_encode(random_bytes(12)), '+/', 'Kx'), '=');
            }
            if (mb_strlen($motDePasse) < 10) {
                throw new RuntimeException('Mot de passe trop court (10 caractères minimum).');
            }
            $empreinte = password_hash($motDePasse, PASSWORD_DEFAULT);
            $con = Database::barber($tenant->base);
            $ajout = $con->prepare("INSERT INTO utilisateurs (prenom, nom, email, mot_de_passe, role) VALUES (?, ?, ?, ?, 'gerant')");
            $ajout->bind_param('ssss', $prenom, $nom, $email, $empreinte);
            $ajout->execute();
            $maj = Database::commune()->prepare('UPDATE barberpay_comptes SET email_gerant = ? WHERE id = ? AND email_gerant IS NULL');
            $id = $tenant->id;
            $maj->bind_param('si', $email, $id);
            $maj->execute();
            echo "Gérant {$prenom} ({$email}) créé pour « {$tenant->sousDomaine} ».\n";
            if ($genere) {
                echo "Mot de passe généré (affiché une seule fois) : {$motDePasse}\n";
            }
            exit(0);

        case 'cloturer':
            $cible = bp_option($options, 'compte', false);
            if ($cible === null && !isset($options['tous'])) {
                fwrite(STDERR, "Précisez --compte=<sous-domaine> ou --tous.\n");
                exit(2);
            }
            $echecs = 0;
            foreach ($cible !== null ? [bp_compte($cible)] : Tenant::tous(Database::commune()) as $tenant) {
                if (!$tenant->estActif()) {
                    continue;
                }
                try {
                    $con = Database::barber($tenant->base);
                    $faites = (new BarberPay\Clotures($con, $tenant, new BarberPay\Journal($con, $tenant)))->rattraper();
                    echo sprintf("  %-20s %s\n", $tenant->sousDomaine, $faites ? implode(', ', $faites) : 'rien à clôturer');
                } catch (Throwable $erreur) {
                    $echecs++;
                    echo sprintf("  %-20s ÉCHEC : %s\n", $tenant->sousDomaine, $erreur->getMessage());
                }
            }
            exit($echecs ? 1 : 0);

        // Inscription en ligne (étape 6) : appelées par /var/kdSite/scriptsV2/barber_creation.py, réponses en JSON.
        case 'compte:creer':
        case 'compte:activer':
        case 'compte:annuler':
            $creation = new BarberPay\Creation(Database::commune(), Database::serveur());
            try {
                if ($commande === 'compte:creer') {
                    $donnees = json_decode((string) stream_get_contents(STDIN), true);
                    if (!is_array($donnees)) {
                        throw new RuntimeException('JSON attendu sur l\'entrée standard.');
                    }
                    $reponse = ['status' => 'success'] + $creation->creer($donnees);
                } else {
                    $sousDomaine = strtolower((string) bp_option($options, 'sous-domaine'));
                    if ($commande === 'compte:activer') {
                        $creation->activer($sousDomaine);
                        $reponse = ['status' => 'success'];
                    } else {
                        $reponse = ['status' => 'success', 'supprime' => $creation->annuler($sousDomaine)];
                    }
                }
                echo json_encode($reponse, JSON_UNESCAPED_UNICODE) . "\n";
                exit(0);
            } catch (Throwable $erreur) {
                echo json_encode(['status' => 'error', 'message' => $erreur->getMessage()], JSON_UNESCAPED_UNICODE) . "\n";
                exit(1);
            }

        case 'demo:reinitialiser':
            $stats = (new BarberPay\Demo(Database::commune(), Database::serveur()))->reinitialiser();
            echo date('Y-m-d H:i:s') . " démo recréée : {$stats['jours']} jour(s), {$stats['tickets']} ticket(s), {$stats['clotures']} clôture(s).\n";
            exit(0);

        case 'integrite':
            $tenant = bp_compte((string) bp_option($options, 'compte'));
            $resultat = (new BarberPay\Integrite(Database::barber($tenant->base), $tenant))->verifier();
            echo ($resultat['ok'] ? 'INTÈGRE' : 'ANOMALIES') . " — {$resultat['tickets']} ticket(s), {$resultat['clotures']} clôture(s), {$resultat['evenements']} événement(s) contrôlés\n";
            foreach ($resultat['erreurs'] as $erreur) {
                echo '  - ' . $erreur . "\n";
            }
            exit($resultat['ok'] ? 0 : 3);

        default:
            $aide = file_get_contents(__FILE__);
            preg_match('#/\*\*(.*?)\*/#s', (string) $aide, $m);
            echo preg_replace('/^\s*\* ?/m', '', trim($m[1] ?? '')) . "\n";
            exit($commande === 'aide' ? 0 : 2);
    }
} catch (Throwable $erreur) {
    fwrite(STDERR, 'Erreur : ' . $erreur->getMessage() . "\n");
    exit(1);
}
