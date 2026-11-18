<?php
/**
 * BarberPay — historique des tickets : consulter, réimprimer (duplicata), annuler (gérant).
 *   ?jour=AAAA-MM-JJ   tickets du jour (par défaut aujourd'hui)
 *   ?numero=123        recherche d'un ticket par son numéro, tous jours confondus
 */
declare(strict_types=1);

use BarberPay\Clotures;
use BarberPay\Montant;
use BarberPay\Ventes;
use function BarberPay\e;

require __DIR__ . '/app/poste.php';

$aujourdhui = date('Y-m-d');
$jour = (string) ($_GET['jour'] ?? $aujourdhui);
$d = DateTime::createFromFormat('!Y-m-d', $jour);
if ($d === false || $d->format('Y-m-d') !== $jour || $jour > $aujourdhui) {
    $jour = $aujourdhui;
    $d = new DateTime($jour);
}
$numero = isset($_GET['numero']) && ctype_digit((string) $_GET['numero']) ? (int) $_GET['numero'] : null;

$sql = "SELECT t.id, t.numero, t.type, t.date_ticket, t.total_ttc, t.nb_articles, t.jeton_public,
               (SELECT o.numero FROM tickets o WHERE o.id = t.ticket_origine_id) AS origine,
               (SELECT a.numero FROM tickets a WHERE a.ticket_origine_id = t.id) AS annule_par,
               (SELECT GROUP_CONCAT(DISTINCT p.moyen ORDER BY p.rang) FROM ticket_paiements p WHERE p.ticket_id = t.id) AS moyens,
               (SELECT COUNT(*) FROM duplicatas x WHERE x.ticket_id = t.id) AS duplicatas,
               u.prenom AS barbier
        FROM tickets t LEFT JOIN utilisateurs u ON u.id = t.barbier_id
        WHERE t.chaine = 'reel' AND ";
if ($numero !== null) {
    $requete = $con->prepare($sql . 't.numero = ?');
    $requete->bind_param('i', $numero);
} else {
    $de = $jour . ' 00:00:00';
    $a = Clotures::lendemain($jour) . ' 00:00:00';
    $requete = $con->prepare($sql . 't.date_ticket >= ? AND t.date_ticket < ? ORDER BY t.numero DESC');
    $requete->bind_param('ss', $de, $a);
}
$requete->execute();
$tickets = $requete->get_result()->fetch_all(MYSQLI_ASSOC);

$totalJour = array_sum(array_map(static fn (array $t): int => (int) $t['total_ttc'], $tickets));
$journeeFermee = $clotures->journeeCloturee($aujourdhui);
$gerant = $operateur['role'] === 'gerant';
$precedent = (clone $d)->modify('-1 day')->format('Y-m-d');
$suivant = (clone $d)->modify('+1 day')->format('Y-m-d');
$libelleJour = static function (string $j) use ($aujourdhui): string {
    if ($j === $aujourdhui) {
        return 'Aujourd\'hui';
    }
    $jours = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
    $dt = new DateTime($j);
    return ucfirst($jours[(int) $dt->format('w')]) . ' ' . $dt->format('d/m/Y');
};

poste_haut('Historique', 'historique');
?>
<div class="entete-page">
    <div>
        <h1>Historique</h1>
        <p class="sous-titre"><?= $numero !== null ? 'Recherche du ticket n° ' . e(Ventes::numeroAffiche($numero)) : e($libelleJour($jour)) . ' · ' . count($tickets) . ' ticket' . (count($tickets) > 1 ? 's' : '') . ' · ' . e(Montant::format($totalJour)) ?></p>
    </div>
    <form method="get" class="recherche-ticket" role="search">
        <label for="numero" class="visuellement-cache">Numéro de ticket</label>
        <input type="text" id="numero" name="numero" inputmode="numeric" pattern="\d*" placeholder="N° de ticket" value="<?= $numero !== null ? e((string) $numero) : '' ?>">
        <button class="bouton" type="submit">Chercher</button>
    </form>
</div>

<nav class="navigation-jours" aria-label="Choix du jour">
    <a class="bouton" href="/historique.php?jour=<?= e($precedent) ?>" aria-label="Jour précédent"><?= icone('retour', 18) ?></a>
    <form method="get">
        <label for="jour" class="visuellement-cache">Jour</label>
        <input type="date" id="jour" name="jour" value="<?= e($jour) ?>" max="<?= e($aujourdhui) ?>" onchange="this.form.submit()">
    </form>
    <?php if ($jour < $aujourdhui): ?>
        <a class="bouton" href="/historique.php?jour=<?= e($suivant) ?>" aria-label="Jour suivant"><span class="fleche-droite"><?= icone('retour', 18) ?></span></a>
        <a class="bouton bouton-discret" href="/historique.php">Aujourd'hui</a>
    <?php endif; ?>
</nav>

<?php if ($journeeFermee && $jour === $aujourdhui && $numero === null): ?>
    <p class="flash flash-succes">La journée est clôturée (Z fait) : plus de vente ni d'annulation aujourd'hui.</p>
<?php endif; ?>

<?php if (!$tickets): ?>
    <div class="vide"><p><?= $numero !== null ? 'Aucun ticket ne porte ce numéro.' : 'Aucun ticket ce jour-là.' ?></p></div>
<?php else: ?>
    <ul class="liste">
        <?php foreach ($tickets as $t):
            $annulation = $t['type'] === 'annulation';
            $annule = $t['annule_par'] !== null;
            $heure = substr((string) $t['date_ticket'], 11, 5);
            $moyens = array_map(static fn (string $m): string => Ventes::MOYENS[$m] ?? $m, array_filter(explode(',', (string) $t['moyens'])));
            ?>
            <li class="ligne<?= $annule ? ' inactive' : '' ?>">
                <div class="ligne-infos">
                    <span class="ligne-nom">N° <?= e(Ventes::numeroAffiche((int) $t['numero'])) ?></span>
                    <span class="ligne-meta"><?= $numero !== null ? e(substr((string) $t['date_ticket'], 8, 2) . '/' . substr((string) $t['date_ticket'], 5, 2) . ' ') : '' ?><?= e($heure) ?></span>
                    <?php if ($annulation): ?><span class="badge badge-alerte">Annulation du n° <?= e(Ventes::numeroAffiche((int) $t['origine'])) ?></span><?php endif; ?>
                    <?php if ($annule): ?><span class="badge">Annulé (n° <?= e(Ventes::numeroAffiche((int) $t['annule_par'])) ?>)</span><?php endif; ?>
                    <span class="ligne-meta"><?= e(implode(' + ', $moyens) ?: '—') ?><?= $t['barbier'] ? ' · ' . e((string) $t['barbier']) : '' ?></span>
                    <?php if ((int) $t['duplicatas'] > 0): ?><span class="ligne-meta"><?= (int) $t['duplicatas'] ?> duplicata<?= (int) $t['duplicatas'] > 1 ? 's' : '' ?></span><?php endif; ?>
                    <span class="ligne-prix<?= (int) $t['total_ttc'] < 0 ? ' negatif' : '' ?>"><?= e(Montant::format((int) $t['total_ttc'])) ?></span>
                </div>
                <div class="ligne-actions">
                    <a class="bouton bouton-icone" href="/t/<?= e((string) $t['jeton_public']) ?>" target="_blank" rel="noopener" aria-label="Voir le ticket n° <?= e(Ventes::numeroAffiche((int) $t['numero'])) ?>"><?= icone('oeil', 18) ?></a>
                    <button type="button" class="bouton" data-duplicata="<?= (int) $t['id'] ?>"><?= icone('imprimante', 18) ?>Réimprimer</button>
                    <?php if (!$annulation && !$annule): ?>
                        <button type="button" class="bouton" data-annuler="<?= (int) $t['id'] ?>" data-numero="<?= e(Ventes::numeroAffiche((int) $t['numero'])) ?>" data-montant="<?= e(Montant::format((int) $t['total_ttc'])) ?>"
                            <?php if (!$gerant): ?>data-refus="Seul un gérant peut annuler un ticket."<?php elseif ($journeeFermee): ?>data-refus="La journée est clôturée : l'annulation sera possible demain."<?php endif; ?>>Annuler</button>
                    <?php endif; ?>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
<?php poste_bas(); ?>
