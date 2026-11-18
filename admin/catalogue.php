<?php
declare(strict_types=1);

use BarberPay\Articles;
use BarberPay\Csrf;
use BarberPay\Flash;
use BarberPay\Montant;
use BarberPay\Parametres;
use BarberPay\Tva;
use function BarberPay\e;

require __DIR__ . '/../app/admin.php';

$articles = new Articles($con);
$type = ($_GET['type'] ?? '') === Articles::PRODUIT ? Articles::PRODUIT : Articles::SERVICE;
$urlPage = '/admin/catalogue.php?type=' . $type;

/** Services proposés au premier démarrage (modifiables ensuite). */
const SERVICES_COURANTS = [
    ['Coupe homme', '25', 30],
    ['Dégradé', '28', 35],
    ['Coupe + barbe', '38', 45],
    ['Taille de barbe', '15', 20],
    ['Coupe enfant (-12 ans)', '18', 20],
    ['Contours & finitions', '10', 10],
];

if (admin_post()) {
    $action = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    switch ($action) {
        case 'haut':
        case 'bas':
            $articles->deplacer($id, $action === 'haut' ? -1 : 1);
            break;
        case 'basculer':
            $article = $articles->trouver($id);
            if ($article !== null) {
                $articles->basculerActif($id);
                Flash::succes('« ' . $article['nom'] . ' » ' . ($article['actif'] ? 'désactivé : il n\'apparaît plus en caisse.' : 'réactivé.'));
            }
            break;
        case 'services_courants':
            if ($articles->compter()[Articles::SERVICE] === 0) {
                foreach (SERVICES_COURANTS as [$nom, $prix, $duree]) {
                    $articles->enregistrer(Articles::SERVICE, ['nom' => $nom, 'prix' => $prix, 'duree_minutes' => (string) $duree, 'taux_tva' => Tva::DEFAUT]);
                }
                Flash::succes(count(SERVICES_COURANTS) . ' services ajoutés. Ajustez les noms et les prix à votre salon.');
            }
            break;
    }
    admin_rediriger($urlPage);
}

$parametres = (new Parametres($con, $tenant))->charger();
$assujetti = $parametres['regime_tva'] === Parametres::ASSUJETTI;
$nombres = $articles->compter();
$liste = $articles->lister($type);
$actifs = array_values(array_filter($liste, static fn (array $a): bool => (bool) $a['actif']));
$inactifs = array_values(array_filter($liste, static fn (array $a): bool => !$a['actif']));
$singulier = $type === Articles::SERVICE ? 'service' : 'produit';

/** Une ligne d'article. */
$afficherLigne = static function (array $article, int $rang, int $total) use ($type, $assujetti): void {
    $id = (int) $article['id'];
    $stockBas = $article['stock'] !== null && $article['stock_alerte'] !== null && (int) $article['stock'] <= (int) $article['stock_alerte'];
    ?>
    <li class="ligne<?= $article['actif'] ? '' : ' inactive' ?>">
        <div class="ligne-infos">
            <span class="ligne-nom"><?= e($article['nom']) ?></span>
            <?php if ($type === Articles::SERVICE && $article['duree_minutes'] !== null): ?>
                <span class="ligne-meta"><?= icone('horloge', 14) ?><?= (int) $article['duree_minutes'] ?> min</span>
            <?php elseif ($type === Articles::PRODUIT): ?>
                <span class="ligne-meta">
                    <?= $article['stock'] === null ? 'Stock non suivi' : 'Stock ' . (int) $article['stock'] ?>
                    <?php if ($stockBas): ?><span class="badge badge-alerte">Stock bas</span><?php endif; ?>
                </span>
            <?php endif; ?>
            <?php if ($assujetti): ?><span class="ligne-meta">TVA <?= e(Tva::libelle((int) $article['taux_tva'])) ?></span><?php endif; ?>
            <span class="ligne-prix"><?= e(Montant::formatCourt((int) $article['prix_ttc'])) ?></span>
        </div>
        <div class="ligne-actions">
            <?php if ($article['actif']): ?>
                <form method="post">
                    <?= Csrf::champ() ?><input type="hidden" name="id" value="<?= $id ?>">
                    <button class="bouton bouton-icone bouton-discret" name="action" value="haut" aria-label="Monter « <?= e($article['nom']) ?> »"<?= $rang === 0 ? ' disabled' : '' ?>><?= icone('haut', 20) ?></button>
                    <button class="bouton bouton-icone bouton-discret" name="action" value="bas" aria-label="Descendre « <?= e($article['nom']) ?> »"<?= $rang === $total - 1 ? ' disabled' : '' ?>><?= icone('bas', 20) ?></button>
                </form>
            <?php endif; ?>
            <a class="bouton bouton-icone" href="/admin/article.php?type=<?= e($type) ?>&amp;id=<?= $id ?>" aria-label="Modifier « <?= e($article['nom']) ?> »"><?= icone('crayon', 18) ?></a>
            <form method="post">
                <?= Csrf::champ() ?><input type="hidden" name="id" value="<?= $id ?>">
                <button class="bouton" name="action" value="basculer"><?= $article['actif'] ? 'Désactiver' : 'Réactiver' ?></button>
            </form>
        </div>
    </li>
    <?php
};

admin_haut('Catalogue', 'catalogue');
?>
<div class="entete-page">
    <div>
        <h1>Catalogue</h1>
        <p class="sous-titre">Ce que vous vendez en caisse. L'ordre ici est celui des vignettes de la caisse.</p>
    </div>
    <a class="bouton bouton-principal" href="/admin/article.php?type=<?= e($type) ?>"><?= icone('plus', 20) ?>Ajouter un <?= e($singulier) ?></a>
</div>

<nav class="onglets" aria-label="Type d'article">
    <a href="/admin/catalogue.php?type=service"<?= $type === Articles::SERVICE ? ' aria-current="page"' : '' ?>><?= icone('ciseaux', 20) ?>Services <span class="nombre">(<?= $nombres[Articles::SERVICE] ?>)</span></a>
    <a href="/admin/catalogue.php?type=produit"<?= $type === Articles::PRODUIT ? ' aria-current="page"' : '' ?>><?= icone('flacon', 20) ?>Produits <span class="nombre">(<?= $nombres[Articles::PRODUIT] ?>)</span></a>
</nav>

<?php if (!$liste): ?>
    <div class="vide">
        <?php if ($type === Articles::SERVICE): ?>
            <p>Aucun service pour l'instant. Commencez avec les services courants d'un barber, puis ajustez les prix.</p>
            <form method="post">
                <?= Csrf::champ() ?>
                <button class="bouton bouton-principal" name="action" value="services_courants">Ajouter les services courants</button>
            </form>
            <a class="bouton" href="/admin/article.php?type=service">Créer mon premier service</a>
        <?php else: ?>
            <p>Aucun produit. Ajoutez les produits que vous vendez au salon (cire, huile à barbe…).</p>
            <a class="bouton bouton-principal" href="/admin/article.php?type=produit"><?= icone('plus', 20) ?>Ajouter un produit</a>
        <?php endif; ?>
    </div>
<?php else: ?>
    <ul class="liste">
        <?php foreach ($actifs as $rang => $article) { $afficherLigne($article, $rang, count($actifs)); } ?>
    </ul>
    <?php if ($inactifs): ?>
        <h2 class="titre-section">Désactivés (<?= count($inactifs) ?>)</h2>
        <ul class="liste">
            <?php foreach ($inactifs as $rang => $article) { $afficherLigne($article, $rang, count($inactifs)); } ?>
        </ul>
    <?php endif; ?>
<?php endif; ?>
<?php admin_bas(); ?>
