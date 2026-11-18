<?php
/** BarberPay — conformité : contrôle d'intégrité, archives signées, attestation de l'éditeur, mode formation. */
declare(strict_types=1);

use BarberPay\Archives;
use BarberPay\Csrf;
use BarberPay\Flash;
use BarberPay\Integrite;
use BarberPay\Journal;
use BarberPay\Montant;
use function BarberPay\e;

require __DIR__ . '/../app/admin.php';

$resultat = null;
if (admin_post()) {
    switch ((string) ($_POST['action'] ?? '')) {
        case 'integrite':
            $resultat = (new Integrite($con, $tenant))->verifier();
            $journal->ecrire(Journal::CONTROLE_INTEGRITE, ['resultat' => $resultat['ok'] ? 'intègre' : 'anomalies', 'erreurs' => count($resultat['erreurs'])], (int) $utilisateur['id']);
            $_SESSION['dernier_controle'] = $resultat + ['le' => date('d/m/Y à H:i')];
            break;
        case 'formation':
            $active = empty($_SESSION['formation']);
            $_SESSION['formation'] = $active;
            $journal->ecrire(Journal::MODE_FORMATION, ['actif' => $active], (int) $utilisateur['id']);
            Flash::succes($active ? 'Mode formation activé sur ce poste : les tickets n\'ont aucune valeur et ne comptent nulle part.' : 'Mode formation désactivé : retour aux vraies ventes.');
            break;
    }
    admin_rediriger('/admin/conformite.php');
}

$controle = $_SESSION['dernier_controle'] ?? null;
$archives = new Archives($con, $tenant, $journal);
$liste = $archives->lister();
$formation = !empty($_SESSION['formation']);
$editeur = require __DIR__ . '/../app/editeur.php';
$editeurComplet = !in_array('À COMPLÉTER', $editeur, true);
$moisFr = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

admin_haut('Conformité', 'conformite');
?>
<div class="entete-page">
    <div>
        <h1>Conformité</h1>
        <p class="sous-titre">Logiciel de caisse : inaltérabilité, sécurisation, conservation et archivage (art. 286 I 3° bis du CGI).</p>
    </div>
</div>

<section class="carte" aria-labelledby="titre-integrite">
    <h2 id="titre-integrite">Contrôle d'intégrité</h2>
    <p class="aide">Relit tous les tickets, clôtures, événements et archives, recalcule leurs empreintes et vérifie leurs signatures.
        Une modification faite directement dans la base de données serait détectée.</p>
    <?php if ($controle): ?>
        <p class="flash <?= $controle['ok'] ? 'flash-succes' : 'flash-erreur' ?>" role="status">
            <?= $controle['ok'] ? 'Intègre' : 'Anomalies détectées' ?> — contrôle du <?= e($controle['le']) ?> :
            <?= (int) $controle['tickets'] ?> ticket(s), <?= (int) $controle['clotures'] ?> clôture(s), <?= (int) $controle['evenements'] ?> événement(s).
        </p>
        <?php if ($controle['erreurs']): ?>
            <ul class="erreurs-integrite"><?php foreach ($controle['erreurs'] as $erreur): ?><li><?= e($erreur) ?></li><?php endforeach; ?></ul>
        <?php endif; ?>
    <?php endif; ?>
    <form method="post"><?= Csrf::champ() ?><button class="bouton bouton-principal" name="action" value="integrite"><?= icone('bouclier', 18) ?>Lancer le contrôle</button></form>
</section>

<section class="carte" aria-labelledby="titre-archives">
    <h2 id="titre-archives">Archives</h2>
    <p class="aide">Une archive par mois et par année clôturés : toutes les données de la période dans un fichier ZIP, avec son empreinte signée.
        Conservez-les 6 ans. Elles sont créées au premier téléchargement, puis ne changent plus.</p>
    <?php if (!$liste): ?>
        <div class="vide"><p>Aucun mois clôturé pour l'instant. La clôture du mois se fait automatiquement au début du mois suivant.</p></div>
    <?php else: ?>
        <ul class="liste">
            <?php foreach ($liste as $a):
                $nom = $a['type'] === 'mois' ? ucfirst($moisFr[(int) substr($a['periode'], 5, 2) - 1]) . ' ' . substr($a['periode'], 0, 4) : 'Année ' . $a['periode'];
                $verifiee = $a['id'] !== null ? $archives->verifier($a) : null; ?>
                <li class="ligne">
                    <div class="ligne-infos">
                        <span class="ligne-nom"><?= e($nom) ?></span>
                        <span class="ligne-meta"><?= $a['id'] === null ? 'archive pas encore créée' : ($verifiee ? 'archive vérifiée · ' . number_format((int) $a['taille'] / 1024, 0, ',', ' ') . ' Ko' : 'ARCHIVE MODIFIÉE OU ABSENTE') ?></span>
                        <span class="ligne-prix"><?= e(Montant::format((int) $a['net_ttc'])) ?></span>
                    </div>
                    <div class="ligne-actions"><a class="bouton" href="/admin/telecharger.php?quoi=archive&amp;id=<?= (int) $a['cloture_id'] ?>"><?= icone('export', 18) ?>Télécharger</a></div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<section class="carte" aria-labelledby="titre-attestation">
    <h2 id="titre-attestation">Attestation de l'éditeur</h2>
    <p class="aide">Document à conserver et à présenter en cas de contrôle : l'éditeur atteste que BarberPay respecte les conditions
        d'inaltérabilité, de sécurisation, de conservation et d'archivage des données.</p>
    <?php if (!$editeurComplet): ?>
        <p class="flash flash-erreur">Les informations de l'éditeur ne sont pas encore renseignées (fichier app/editeur.php) : l'attestation n'est pas encore valable.</p>
    <?php endif; ?>
    <a class="bouton" href="/admin/attestation.php"><?= icone('oeil', 18) ?>Voir l'attestation</a>
</section>

<section class="carte<?= $formation ? ' carte-formation' : '' ?>" aria-labelledby="titre-formation">
    <h2 id="titre-formation">Mode formation</h2>
    <p class="aide">Pour apprendre à se servir de la caisse : les tickets portent la mention « formation », ont leur propre numérotation,
        ne comptent dans aucun total, aucun Z ni aucun export, et ne touchent pas au stock. Le mode s'applique à ce poste uniquement.</p>
    <form method="post"><?= Csrf::champ() ?>
        <button class="bouton<?= $formation ? ' bouton-principal' : '' ?>" name="action" value="formation"><?= $formation ? 'Quitter le mode formation' : 'Activer le mode formation' ?></button>
    </form>
</section>
<?php admin_bas(); ?>
