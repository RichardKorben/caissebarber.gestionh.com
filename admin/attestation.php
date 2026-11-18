<?php
/**
 * BarberPay — attestation individuelle de l'éditeur (art. 286 I 3° bis du CGI), à imprimer ou enregistrer en PDF.
 *
 * Reprend les éléments demandés par l'administration (éditeur, logiciel et version, utilisateur, engagement sur les
 * quatre conditions). Le texte doit être relu et aligné sur le modèle officiel publié au BOFiP (BOI-LETTRE-000242)
 * avant la première remise : voir docs/DEV.md.
 */
declare(strict_types=1);

use BarberPay\Parametres;
use function BarberPay\e;

require __DIR__ . '/../app/admin.php';

$editeur = require __DIR__ . '/../app/editeur.php';
$complet = !in_array('À COMPLÉTER', $editeur, true);
$p = (new Parametres($con, $tenant))->charger();
$client = (string) ($p['raison_sociale'] ?: $p['nom_salon']);
$adresseClient = trim(($p['adresse'] ?? '') . ', ' . ($p['code_postal'] ?? '') . ' ' . ($p['ville'] ?? ''), ' ,');
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Attestation — <?= e($editeur['logiciel']) ?> — <?= e($client) ?></title>
    <link rel="stylesheet" href="/public/css/attestation.css?v=<?= e(BarberPay\VERSION) ?>">
</head>
<body>
<?php if (!$complet): ?>
    <p class="avertissement non-imprime">Brouillon : les informations de l'éditeur sont à compléter dans <code>app/editeur.php</code>. Cette attestation n'est pas valable en l'état.</p>
<?php endif; ?>
<main class="page<?= $complet ? '' : ' brouillon' ?>">
    <h1>Attestation individuelle de l'éditeur</h1>
    <p class="sous-titre">relative à un logiciel de caisse satisfaisant aux conditions d'inaltérabilité, de sécurisation, de conservation
        et d'archivage des données en vue du contrôle de l'administration fiscale (article 286, I, 3° bis du code général des impôts)</p>

    <h2>L'éditeur</h2>
    <dl>
        <div><dt>Dénomination</dt><dd><?= e($editeur['raison_sociale']) ?></dd></div>
        <div><dt>Adresse</dt><dd><?= e($editeur['adresse'] . ', ' . $editeur['code_postal'] . ' ' . $editeur['ville']) ?></dd></div>
        <div><dt>SIREN</dt><dd><?= e($editeur['siren']) ?></dd></div>
        <div><dt>Représenté par</dt><dd><?= e($editeur['representant']) ?></dd></div>
    </dl>

    <h2>Le logiciel</h2>
    <dl>
        <div><dt>Nom</dt><dd><?= e($editeur['logiciel']) ?></dd></div>
        <div><dt>Version majeure</dt><dd><?= e($editeur['version_majeure']) ?> (version installée : <?= e(BarberPay\VERSION) ?>)</dd></div>
    </dl>

    <h2>L'utilisateur</h2>
    <dl>
        <div><dt>Entreprise</dt><dd><?= e($client) ?></dd></div>
        <div><dt>Enseigne</dt><dd><?= e((string) $p['nom_salon']) ?></dd></div>
        <div><dt>Adresse</dt><dd><?= e($adresseClient ?: '—') ?></dd></div>
        <div><dt>SIRET</dt><dd><?= e((string) ($p['siret'] ?? '—')) ?></dd></div>
    </dl>

    <h2>Engagement</h2>
    <p>Je soussigné, représentant de l'éditeur désigné ci-dessus, atteste que le logiciel <?= e($editeur['logiciel']) ?>, dans sa version
        majeure <?= e($editeur['version_majeure']) ?>, mis à la disposition de l'utilisateur désigné ci-dessus, satisfait aux conditions
        d'inaltérabilité, de sécurisation, de conservation et d'archivage des données prévues au 3° bis du I de l'article 286 du code
        général des impôts, notamment :</p>
    <ul>
        <li><strong>Inaltérabilité</strong> : les données d'encaissement ne peuvent être ni modifiées ni supprimées ; toute correction
            prend la forme d'un ticket d'annulation, enregistré à son tour.</li>
        <li><strong>Sécurisation</strong> : chaque ticket, clôture et événement est chaîné au précédent et signé électroniquement ;
            un journal des événements trace les opérations sensibles.</li>
        <li><strong>Conservation</strong> : les données sont conservées et des clôtures journalières, mensuelles et annuelles
            comportant un grand total perpétuel sont établies.</li>
        <li><strong>Archivage</strong> : les données de chaque période clôturée sont archivées dans un fichier signé, et peuvent être
            restituées à l'administration sous une forme lisible.</li>
    </ul>
    <p>L'éditeur s'engage à ce que les versions mineures ultérieures de cette version majeure respectent les mêmes conditions.</p>

    <div class="signature">
        <p>Fait à <?= e($editeur['ville']) ?>, le <?= e(date('d/m/Y')) ?></p>
        <p>Pour l'éditeur, <?= e($editeur['representant']) ?></p>
        <p class="zone-signature">Signature</p>
    </div>
    <p class="note">Attestation à conserver par l'utilisateur et à présenter à toute demande de l'administration. L'utilisateur atteste de
        son côté utiliser le logiciel dans les conditions prévues par l'éditeur.</p>
</main>
<p class="actions non-imprime">
    <?php if ($complet): ?><button type="button" onclick="window.print()">Imprimer ou enregistrer en PDF</button><?php endif; ?>
    <a href="/admin/conformite.php">Retour</a>
</p>
</body>
</html>
