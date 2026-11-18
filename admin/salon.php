<?php
declare(strict_types=1);

use BarberPay\Csrf;
use BarberPay\Database;
use BarberPay\Flash;
use BarberPay\Montant;
use BarberPay\Parametres;
use function BarberPay\e;

require __DIR__ . '/../app/admin.php';

$parametres = new Parametres($con, $tenant);
$p = $parametres->charger();
$erreurs = [];
$valeurs = [
    'nom_salon' => (string) $p['nom_salon'],
    'raison_sociale' => (string) ($p['raison_sociale'] ?? ''),
    'adresse' => (string) ($p['adresse'] ?? ''),
    'code_postal' => (string) ($p['code_postal'] ?? ''),
    'ville' => (string) ($p['ville'] ?? ''),
    'telephone' => (string) ($p['telephone'] ?? ''),
    'siret' => (string) ($p['siret'] ?? ''),
    'tva_intracom' => (string) ($p['tva_intracom'] ?? ''),
    'regime_tva' => (string) $p['regime_tva'],
    'seuil_services' => Montant::saisie((int) $p['seuil_services']),
    'seuil_services_majore' => Montant::saisie((int) $p['seuil_services_majore']),
    'seuil_ventes' => Montant::saisie((int) $p['seuil_ventes']),
    'seuil_ventes_majore' => Montant::saisie((int) $p['seuil_ventes_majore']),
    'mention_ticket' => (string) ($p['mention_ticket'] ?? ''),
    'animation_poteau' => (string) $p['animation_poteau'],
];

if (admin_post()) {
    $valeurs = array_merge($valeurs, array_intersect_key($_POST, $valeurs));
    $valeurs['animation_poteau'] = empty($_POST['animation_poteau']) ? '0' : '1';
    $erreurs = $parametres->enregistrer($_POST, Database::commune());
    if (!$erreurs) {
        Flash::succes('Informations du salon enregistrées.');
        admin_rediriger('/admin/salon.php');
    }
}

$franchise = $valeurs['regime_tva'] === Parametres::FRANCHISE;
admin_haut('Salon', 'salon');
?>
<div class="entete-page">
    <div>
        <h1>Salon</h1>
        <p class="sous-titre">Ces informations figurent sur les tickets. Le SIRET et l'adresse sont obligatoires sur un ticket.</p>
    </div>
</div>

<?php if ($erreurs): ?>
    <p class="flash flash-erreur" role="alert">Vérifiez les champs signalés.</p>
<?php endif; ?>

<form method="post" novalidate>
    <?= Csrf::champ() ?>

    <section class="carte" aria-labelledby="titre-identite">
        <h2 id="titre-identite">Identité</h2>
        <div class="grille-champs">
            <?= champ('nom_salon', 'Nom du salon', $valeurs['nom_salon'], $erreurs, ['requis' => true, 'maxlength' => 120, 'aide' => 'L\'enseigne, affichée en caisse et en haut du ticket.']) ?>
            <?= champ('raison_sociale', 'Nom de l\'entreprise', $valeurs['raison_sociale'], $erreurs, ['maxlength' => 160, 'aide' => 'Votre nom (micro-entrepreneur) ou la raison sociale.']) ?>
            <?= champ('siret', 'SIRET', $valeurs['siret'], $erreurs, ['inputmode' => 'numeric', 'maxlength' => 17, 'placeholder' => '123 456 789 00012']) ?>
            <?= champ('telephone', 'Téléphone', $valeurs['telephone'], $erreurs, ['type' => 'tel', 'maxlength' => 30, 'autocomplete' => 'tel']) ?>
            <?= champ('adresse', 'Adresse', $valeurs['adresse'], $erreurs, ['maxlength' => 190, 'autocomplete' => 'street-address']) ?>
            <?= champ('code_postal', 'Code postal', $valeurs['code_postal'], $erreurs, ['inputmode' => 'numeric', 'maxlength' => 5, 'autocomplete' => 'postal-code']) ?>
            <?= champ('ville', 'Ville', $valeurs['ville'], $erreurs, ['maxlength' => 100, 'autocomplete' => 'address-level2']) ?>
        </div>
    </section>

    <section class="carte" aria-labelledby="titre-tva">
        <h2 id="titre-tva">TVA</h2>
        <fieldset>
            <legend>Régime de TVA</legend>
            <div class="choix">
                <label><input type="radio" name="regime_tva" value="franchise"<?= $franchise ? ' checked' : '' ?>>
                    <span>Franchise en base<small>Vous ne facturez pas la TVA (la plupart des micro-entrepreneurs). Le ticket indique « TVA non applicable, art. 293 B du CGI ».</small></span></label>
                <label><input type="radio" name="regime_tva" value="assujetti"<?= !$franchise ? ' checked' : '' ?>>
                    <span>Assujetti à la TVA<small>Vous facturez la TVA. Le ticket détaille le HT et la TVA par taux.</small></span></label>
            </div>
            <?php if (isset($erreurs['regime_tva'])): ?><p class="erreur-champ"><?= e($erreurs['regime_tva']) ?></p><?php endif; ?>
        </fieldset>
        <div class="grille-champs">
            <?= champ('tva_intracom', 'N° de TVA intracommunautaire', $valeurs['tva_intracom'], $erreurs, ['maxlength' => 20, 'placeholder' => 'FR12345678901', 'aide' => 'Seulement si vous êtes assujetti.']) ?>
        </div>

        <h3 class="titre-section" style="margin-top:22px">Seuils de franchise</h3>
        <p class="aide">Chiffre d'affaires annuel à ne pas dépasser pour rester en franchise. BarberPay vous préviendra quand vous en approcherez.
            Valeurs 2026 préremplies ; à mettre à jour si la loi change.</p>
        <div class="grille-champs">
            <?= champ('seuil_services', 'Services : seuil', $valeurs['seuil_services'], $erreurs, ['inputmode' => 'decimal', 'suffixe' => '€']) ?>
            <?= champ('seuil_services_majore', 'Services : seuil majoré', $valeurs['seuil_services_majore'], $erreurs, ['inputmode' => 'decimal', 'suffixe' => '€']) ?>
            <?= champ('seuil_ventes', 'Ventes : seuil', $valeurs['seuil_ventes'], $erreurs, ['inputmode' => 'decimal', 'suffixe' => '€']) ?>
            <?= champ('seuil_ventes_majore', 'Ventes : seuil majoré', $valeurs['seuil_ventes_majore'], $erreurs, ['inputmode' => 'decimal', 'suffixe' => '€']) ?>
        </div>
    </section>

    <section class="carte" aria-labelledby="titre-ticket">
        <h2 id="titre-ticket">Ticket et affichage</h2>
        <div class="grille-champs">
            <?= champ('mention_ticket', 'Message en bas du ticket', $valeurs['mention_ticket'], $erreurs, ['maxlength' => 255, 'placeholder' => 'Merci et à bientôt !']) ?>
        </div>
        <label class="case"><input type="checkbox" name="animation_poteau" value="1"<?= $valeurs['animation_poteau'] === '1' ? ' checked' : '' ?>> Animer le poteau de barbier (rayures qui tournent)</label>
    </section>

    <div class="actions-formulaire">
        <button type="submit" class="bouton bouton-principal">Enregistrer</button>
    </div>
</form>
<?php admin_bas(); ?>
