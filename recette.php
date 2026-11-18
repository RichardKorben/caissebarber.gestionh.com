<?php
/**
 * BarberPay — recette du jour : ouverture de la caisse, totaux en direct, fermeture avec comptage des espèces
 * et clôture journalière (Z), liste des clôtures.
 */
declare(strict_types=1);

use BarberPay\Clotures;
use BarberPay\Montant;
use BarberPay\Parametres;
use BarberPay\Tva;
use BarberPay\Ventes;
use function BarberPay\e;

require __DIR__ . '/app/poste.php';

$jour = date('Y-m-d');
$totaux = $clotures->totaux($jour, Clotures::lendemain($jour));
$ouverture = $clotures->ouverture($jour);
$fermee = $clotures->journeeCloturee($jour);
$attendues = $clotures->especesAttendues($jour);
$franchise = $parametres['regime_tva'] === Parametres::FRANCHISE;
$aVenir = $parametres['regime_tva_a_venir'] ?? null;
$liste = $clotures->lister(40);
$m = static fn (int $c): string => Montant::format($c);
$panier = $totaux['nb_ventes'] > 0 ? intdiv($totaux['ventes_ttc'], $totaux['nb_ventes']) : 0;
$typesCloture = ['jour' => 'Z du jour', 'mois' => 'Mois', 'annee' => 'Année'];
$moisFr = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
$periode = static function (array $c) use ($moisFr): string {
    return match ($c['type']) {
        'jour' => substr($c['periode'], 8, 2) . '/' . substr($c['periode'], 5, 2) . '/' . substr($c['periode'], 0, 4),
        'mois' => ucfirst($moisFr[(int) substr($c['periode'], 5, 2) - 1]) . ' ' . substr($c['periode'], 0, 4),
        default => (string) $c['periode'],
    };
};

poste_haut('Recette', 'recette');
?>
<div class="entete-page">
    <div>
        <h1>Recette du jour</h1>
        <p class="sous-titre"><?= e((new DateTime())->format('d/m/Y')) ?> · <?= $fermee ? 'journée clôturée' : ($ouverture ? 'caisse ouverte avec ' . e($m((int) $ouverture['fond'])) . ' de fond' : 'caisse non ouverte') ?></p>
    </div>
</div>

<?php if ($aVenir !== null): ?>
    <p class="flash flash-succes">Le passage au régime « <?= $aVenir === Parametres::FRANCHISE ? 'franchise de TVA' : 'assujetti à la TVA' ?> » sera appliqué à la clôture de la journée.</p>
<?php endif; ?>

<section class="tuiles" aria-label="Chiffres du jour">
    <div class="tuile tuile-principale"><span class="tuile-libelle">Chiffre d'affaires net</span><span class="tuile-valeur"><?= e($m($totaux['net_ttc'])) ?></span><span class="tuile-note"><?= $franchise ? 'TVA non applicable' : 'dont TVA ' . e($m($totaux['net_tva'])) ?></span></div>
    <div class="tuile"><span class="tuile-libelle">Tickets</span><span class="tuile-valeur"><?= $totaux['nb_ventes'] ?></span><span class="tuile-note"><?= $totaux['nb_annulations'] ? $totaux['nb_annulations'] . ' annulation(s) : ' . e($m($totaux['annulations_ttc'])) : 'aucune annulation' ?></span></div>
    <div class="tuile"><span class="tuile-libelle">Panier moyen</span><span class="tuile-valeur"><?= e($m($panier)) ?></span><span class="tuile-note">remises : <?= e($m($totaux['detail']['remises'])) ?></span></div>
    <div class="tuile"><span class="tuile-libelle">Espèces attendues</span><span class="tuile-valeur"><?= e($m($attendues)) ?></span><span class="tuile-note">fond <?= e($m((int) ($ouverture['fond'] ?? 0))) ?> + espèces encaissées</span></div>
</section>

<div class="colonnes">
    <section class="carte" aria-labelledby="titre-moyens">
        <h2 id="titre-moyens">Par moyen de paiement</h2>
        <dl class="tableau-montants">
            <?php foreach (Ventes::MOYENS as $code => $nom): ?>
                <div><dt><?= e($nom) ?></dt><dd><?= e($m((int) ($totaux['detail']['par_moyen'][$code] ?? 0))) ?></dd></div>
            <?php endforeach; ?>
        </dl>
    </section>
    <section class="carte" aria-labelledby="titre-types">
        <h2 id="titre-types">Services et produits</h2>
        <dl class="tableau-montants">
            <div><dt>Services</dt><dd><?= e($m($totaux['detail']['par_type']['service'])) ?></dd></div>
            <div><dt>Produits</dt><dd><?= e($m($totaux['detail']['par_type']['produit'])) ?></dd></div>
        </dl>
        <?php if (count($totaux['detail']['par_barbier']) > 1): ?>
            <h3 class="sous-titre-carte">Par barbier</h3>
            <dl class="tableau-montants">
                <?php foreach ($totaux['detail']['par_barbier'] as $b): ?>
                    <div><dt><?= e($b['prenom'] ?? 'Non attribué') ?></dt><dd><?= e($m($b['ttc'])) ?></dd></div>
                <?php endforeach; ?>
            </dl>
        <?php endif; ?>
    </section>
    <?php if (!$franchise && $totaux['detail']['par_taux']): ?>
        <section class="carte" aria-labelledby="titre-tva">
            <h2 id="titre-tva">TVA</h2>
            <table class="tableau">
                <thead><tr><th scope="col">Taux</th><th scope="col">HT</th><th scope="col">TVA</th><th scope="col">TTC</th></tr></thead>
                <tbody>
                <?php foreach ($totaux['detail']['par_taux'] as $t): ?>
                    <tr><td><?= e(Tva::libelle($t['taux'])) ?></td><td><?= e($m($t['ht'])) ?></td><td><?= e($m($t['tva'])) ?></td><td><?= e($m($t['ttc'])) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    <?php endif; ?>
</div>

<?php if (!$fermee): ?>
    <div class="colonnes">
        <?php if (!$ouverture): ?>
            <section class="carte" aria-labelledby="titre-ouverture">
                <h2 id="titre-ouverture">Ouvrir la caisse</h2>
                <p class="aide">Facultatif : indiquez le fond de caisse (monnaie dans le tiroir) pour que l'écart soit calculé à la fermeture.</p>
                <form class="formulaire-ligne" data-ouvrir>
                    <div class="champ"><label for="fond">Fond de caisse</label>
                        <div class="saisie"><input type="text" id="fond" name="fond" inputmode="decimal" placeholder="150,00" autocomplete="off"><span class="suffixe">€</span></div></div>
                    <button type="submit" class="bouton bouton-principal">Ouvrir</button>
                </form>
            </section>
        <?php endif; ?>
        <section class="carte carte-z" aria-labelledby="titre-z">
            <h2 id="titre-z">Fermer la caisse (Z)</h2>
            <p class="aide">Comptez les espèces du tiroir. La clôture du jour (Z) est définitive : plus aucune vente ni annulation aujourd'hui.</p>
            <form class="formulaire-ligne" data-cloturer data-attendues="<?= $attendues ?>" data-net="<?= e($m($totaux['net_ttc'])) ?>">
                <div class="champ"><label for="comptees">Espèces comptées</label>
                    <div class="saisie"><input type="text" id="comptees" name="comptees" inputmode="decimal" placeholder="<?= e(Montant::saisie($attendues)) ?>" autocomplete="off"><span class="suffixe">€</span></div>
                    <p class="aide" id="ecart" aria-live="polite">Attendu : <?= e($m($attendues)) ?></p></div>
                <button type="submit" class="bouton bouton-principal">Clôturer la journée</button>
            </form>
        </section>
    </div>
<?php endif; ?>

<h2 class="titre-section">Clôtures</h2>
<?php if (!$liste): ?>
    <div class="vide"><p>Aucune clôture pour l'instant. Le Z est fait à la fermeture de la caisse ; une journée oubliée est clôturée automatiquement le lendemain.</p></div>
<?php else: ?>
    <ul class="liste">
        <?php foreach ($liste as $c): ?>
            <li class="ligne">
                <div class="ligne-infos">
                    <span class="ligne-nom"><?= e($typesCloture[$c['type']] ?? $c['type']) ?> · <?= e($periode($c)) ?></span>
                    <span class="ligne-meta">n° <?= (int) $c['numero'] ?> · <?= (int) $c['nb_ventes'] ?> ticket(s)<?= $c['automatique'] ? ' · automatique' : '' ?><?= $c['ecart'] !== null && (int) $c['ecart'] !== 0 ? ' · écart ' . e($m((int) $c['ecart'])) : '' ?></span>
                    <span class="ligne-prix"><?= e($m((int) $c['net_ttc'])) ?></span>
                </div>
                <div class="ligne-actions">
                    <a class="bouton" href="/z.php?id=<?= (int) $c['id'] ?>"><?= icone('oeil', 18) ?>Voir</a>
                    <button type="button" class="bouton" data-imprimer-z="<?= (int) $c['id'] ?>"><?= icone('imprimante', 18) ?>Imprimer</button>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
<?php poste_bas(); ?>
