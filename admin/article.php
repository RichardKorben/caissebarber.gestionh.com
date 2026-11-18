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

$articles = new Articles($con, $journal, (int) $utilisateur['id']);
$type = ($_GET['type'] ?? '') === Articles::PRODUIT ? Articles::PRODUIT : Articles::SERVICE;
$id = isset($_GET['id']) ? (int) $_GET['id'] : null;
$article = $id !== null ? $articles->trouver($id) : null;
if ($id !== null && ($article === null || $article['type'] !== $type)) {
    Flash::erreur('Article introuvable.');
    admin_rediriger('/admin/catalogue.php?type=' . $type);
}

$singulier = $type === Articles::SERVICE ? 'service' : 'produit';
$erreurs = [];
$valeurs = [
    'nom' => (string) ($article['nom'] ?? ''),
    'prix' => Montant::saisie(isset($article['prix_ttc']) ? (int) $article['prix_ttc'] : null),
    'taux_tva' => (string) ($article['taux_tva'] ?? Tva::DEFAUT),
    'duree_minutes' => (string) ($article['duree_minutes'] ?? ''),
    'stock' => (string) ($article['stock'] ?? ''),
    'stock_alerte' => (string) ($article['stock_alerte'] ?? ''),
    'code_barre' => (string) ($article['code_barre'] ?? ''),
];

if (admin_post()) {
    $valeurs = array_merge($valeurs, array_intersect_key($_POST, $valeurs));
    $resultat = $articles->enregistrer($type, $_POST, $id);
    $erreurs = $resultat['erreurs'];
    if (!$erreurs) {
        Flash::succes('« ' . trim((string) $_POST['nom']) . ' » enregistré.');
        admin_rediriger('/admin/catalogue.php?type=' . $type);
    }
}

$assujetti = (new Parametres($con, $tenant))->charger()['regime_tva'] === Parametres::ASSUJETTI;
$titre = $article ? 'Modifier « ' . $article['nom'] . ' »' : 'Nouveau ' . $singulier;
admin_haut($titre, 'catalogue');
?>
<a class="lien-retour" href="/admin/catalogue.php?type=<?= e($type) ?>"><?= icone('retour', 18) ?>Catalogue</a>
<div class="entete-page"><h1><?= e($titre) ?></h1></div>

<?php if ($erreurs): ?>
    <p class="flash flash-erreur" role="alert">Vérifiez les champs signalés.</p>
<?php endif; ?>

<form method="post" class="carte" novalidate>
    <?= Csrf::champ() ?>
    <div class="grille-champs">
        <?= champ('nom', 'Nom', $valeurs['nom'], $erreurs, ['requis' => true, 'maxlength' => 80,
            'placeholder' => $type === Articles::SERVICE ? 'Coupe homme' : 'Cire coiffante mate']) ?>
        <?= champ('prix', 'Prix TTC', $valeurs['prix'], $erreurs, ['requis' => true, 'inputmode' => 'decimal', 'suffixe' => '€', 'placeholder' => '25,00']) ?>
        <?php if ($type === Articles::SERVICE): ?>
            <?= champ('duree_minutes', 'Durée', $valeurs['duree_minutes'], $erreurs, ['inputmode' => 'numeric', 'suffixe' => 'min', 'placeholder' => '30',
                'aide' => 'Facultatif. Affichée sur la vignette.']) ?>
        <?php else: ?>
            <?= champ('stock', 'Stock actuel', $valeurs['stock'], $erreurs, ['inputmode' => 'numeric',
                'aide' => 'Laissez vide pour ne pas suivre le stock.']) ?>
            <?= champ('stock_alerte', 'Alerte stock bas à partir de', $valeurs['stock_alerte'], $erreurs, ['inputmode' => 'numeric', 'placeholder' => '3']) ?>
            <?= champ('code_barre', 'Code-barres', $valeurs['code_barre'], $erreurs, ['inputmode' => 'numeric',
                'aide' => 'Facultatif. Scannez-le directement dans ce champ.']) ?>
        <?php endif; ?>
        <div class="champ<?= isset($erreurs['taux_tva']) ? ' champ-en-erreur' : '' ?>">
            <label for="champ-taux_tva">Taux de TVA</label>
            <select id="champ-taux_tva" name="taux_tva" aria-describedby="champ-taux_tva-aide">
                <?php foreach (Tva::TAUX as $taux => $libelle): ?>
                    <option value="<?= $taux ?>"<?= (string) $taux === $valeurs['taux_tva'] ? ' selected' : '' ?>><?= e($libelle) ?></option>
                <?php endforeach; ?>
            </select>
            <p class="aide" id="champ-taux_tva-aide"><?= $assujetti
                ? '20 % pour la coiffure, la barbe et les produits cosmétiques.'
                : 'Vous êtes en franchise de TVA : ce taux ne sera utilisé que si vous passez à la TVA.' ?></p>
            <?php if (isset($erreurs['taux_tva'])): ?><p class="erreur-champ"><?= e($erreurs['taux_tva']) ?></p><?php endif; ?>
        </div>
    </div>
    <div class="actions-formulaire" style="margin-top:22px">
        <button type="submit" class="bouton bouton-principal">Enregistrer</button>
        <a class="bouton bouton-discret" href="/admin/catalogue.php?type=<?= e($type) ?>">Annuler</a>
    </div>
</form>
<?php admin_bas(); ?>
