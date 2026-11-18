<?php
declare(strict_types=1);

use BarberPay\Csrf;
use BarberPay\Equipe;
use BarberPay\Flash;
use function BarberPay\e;

require __DIR__ . '/../app/admin.php';

$equipe = new Equipe($con, $journal, (int) $utilisateur['id']);
$id = isset($_GET['id']) ? (int) $_GET['id'] : null;
$membre = $id !== null ? $equipe->trouver($id) : null;
if ($id !== null && $membre === null) {
    Flash::erreur('Membre introuvable.');
    admin_rediriger('/admin/equipe.php');
}

$erreurs = [];
$valeurs = [
    'prenom' => (string) ($membre['prenom'] ?? ''),
    'nom' => (string) ($membre['nom'] ?? ''),
    'role' => (string) ($membre['role'] ?? Equipe::BARBIER),
    'couleur' => (string) ($membre['couleur'] ?? Equipe::COULEURS[count($equipe->lister()) % count(Equipe::COULEURS)]),
    'email' => (string) ($membre['email'] ?? ''),
];

if (admin_post() && $tenant->demo && (($membre['role'] ?? '') === Equipe::GERANT || ($_POST['role'] ?? '') === Equipe::GERANT)) {
    Flash::erreur(bp_demo_bloque());
    admin_rediriger('/admin/equipe.php');
}
if (admin_post()) {
    $valeurs = array_merge($valeurs, array_intersect_key($_POST, $valeurs));
    $resultat = $equipe->enregistrer($_POST, $id);
    $erreurs = $resultat['erreurs'];
    if (!$erreurs) {
        Flash::succes(trim((string) $_POST['prenom']) . ' enregistré.');
        admin_rediriger('/admin/equipe.php');
    }
}

$estGerant = $valeurs['role'] === Equipe::GERANT;
$aDejaMdp = $membre !== null && $membre['role'] === Equipe::GERANT;
$titre = $membre ? 'Modifier ' . $membre['prenom'] : 'Nouveau membre';
admin_haut($titre, 'equipe');
?>
<a class="lien-retour" href="/admin/equipe.php"><?= icone('retour', 18) ?>Équipe</a>
<div class="entete-page"><h1><?= e($titre) ?></h1></div>

<?php if ($erreurs): ?>
    <p class="flash flash-erreur" role="alert">Vérifiez les champs signalés.</p>
<?php endif; ?>

<form method="post" class="carte" novalidate>
    <?= Csrf::champ() ?>
    <div class="grille-champs">
        <?= champ('prenom', 'Prénom', $valeurs['prenom'], $erreurs, ['requis' => true, 'maxlength' => 60, 'autocomplete' => 'off',
            'aide' => 'Affiché sur la caisse et les tickets.']) ?>
        <?= champ('nom', 'Nom', $valeurs['nom'], $erreurs, ['maxlength' => 60, 'autocomplete' => 'off', 'aide' => 'Facultatif.']) ?>
    </div>

    <fieldset>
        <legend>Rôle</legend>
        <div class="choix">
            <label><input type="radio" name="role" value="barbier"<?= !$estGerant ? ' checked' : '' ?>>
                <span>Barbier<small>Encaisse avec son code PIN. Pas d'accès à l'administration.</small></span></label>
            <label><input type="radio" name="role" value="gerant"<?= $estGerant ? ' checked' : '' ?>>
                <span>Gérant<small>Accès complet, connexion par e-mail et mot de passe.</small></span></label>
        </div>
        <?php if (isset($erreurs['role'])): ?><p class="erreur-champ"><?= e($erreurs['role']) ?></p><?php endif; ?>
    </fieldset>

    <fieldset>
        <legend>Couleur</legend>
        <div class="couleurs">
            <?php foreach (Equipe::COULEURS as $rang => $couleur): ?>
                <label><input type="radio" name="couleur" value="<?= e($couleur) ?>"<?= $couleur === $valeurs['couleur'] ? ' checked' : '' ?> aria-label="Couleur <?= $rang + 1 ?>"><span style="background:<?= e($couleur) ?>"></span></label>
            <?php endforeach; ?>
        </div>
    </fieldset>

    <div class="grille-champs">
        <?= champ('pin', $membre && $membre['a_pin'] ? 'Nouveau code PIN' : 'Code PIN', '', $erreurs, ['type' => 'password', 'inputmode' => 'numeric', 'maxlength' => 6,
            'autocomplete' => 'new-password', 'pattern' => '\d{4,6}',
            'aide' => $membre && $membre['a_pin'] ? '4 à 6 chiffres. Laissez vide pour garder le code actuel.' : '4 à 6 chiffres, pour déverrouiller la caisse.']) ?>
    </div>

    <div class="bloc-gerant" id="bloc-gerant"<?= $estGerant ? '' : ' hidden' ?>>
        <div class="grille-champs">
            <?= champ('email', 'E-mail de connexion', $valeurs['email'], $erreurs, ['type' => 'email', 'autocomplete' => 'off', 'requis' => true]) ?>
            <?= champ('mot_de_passe', $aDejaMdp ? 'Nouveau mot de passe' : 'Mot de passe', '', $erreurs, ['type' => 'password', 'autocomplete' => 'new-password',
                'requis' => !$aDejaMdp, 'aide' => $aDejaMdp ? '10 caractères minimum. Laissez vide pour le garder.' : '10 caractères minimum.']) ?>
        </div>
    </div>

    <div class="actions-formulaire" style="margin-top:22px">
        <button type="submit" class="bouton bouton-principal">Enregistrer</button>
        <a class="bouton bouton-discret" href="/admin/equipe.php">Annuler</a>
    </div>
</form>
<script>
// Affiche l'e-mail et le mot de passe seulement pour un gérant.
document.querySelectorAll('input[name="role"]').forEach(function (radio) {
    radio.addEventListener('change', function () {
        document.getElementById('bloc-gerant').hidden = radio.value !== 'gerant' || !radio.checked;
    });
});
</script>
<?php admin_bas(); ?>
