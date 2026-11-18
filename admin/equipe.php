<?php
declare(strict_types=1);

use BarberPay\Csrf;
use BarberPay\Equipe;
use BarberPay\Flash;
use function BarberPay\e;

require __DIR__ . '/../app/admin.php';

$equipe = new Equipe($con);

if (admin_post()) {
    $id = (int) ($_POST['id'] ?? 0);
    $membre = $equipe->trouver($id);
    switch ((string) ($_POST['action'] ?? '')) {
        case 'basculer':
            $erreur = $equipe->basculerActif($id, (int) $utilisateur['id']);
            if ($erreur !== null) {
                Flash::erreur($erreur);
            } elseif ($membre !== null) {
                Flash::succes($membre['prenom'] . ($membre['actif'] ? ' est désactivé : il n\'apparaît plus en caisse.' : ' est réactivé.'));
            }
            break;
        case 'supprimer_pin':
            if ($membre !== null) {
                $equipe->supprimerPin($id);
                Flash::succes('Code PIN de ' . $membre['prenom'] . ' supprimé.');
            }
            break;
    }
    admin_rediriger('/admin/equipe.php');
}

$membres = $equipe->lister();
$actifs = array_values(array_filter($membres, static fn (array $m): bool => (bool) $m['actif']));
$inactifs = array_values(array_filter($membres, static fn (array $m): bool => !$m['actif']));

$afficher = static function (array $membre) use ($utilisateur): void {
    $id = (int) $membre['id'];
    $nomComplet = trim($membre['prenom'] . ' ' . ($membre['nom'] ?? ''));
    ?>
    <li class="ligne<?= $membre['actif'] ? '' : ' inactive' ?>">
        <div class="ligne-infos">
            <span class="pastille" style="background:<?= e((string) ($membre['couleur'] ?? '#A0A0A0')) ?>" aria-hidden="true"></span>
            <span class="ligne-nom"><?= e($nomComplet) ?><?= $id === (int) $utilisateur['id'] ? ' <span class="badge">vous</span>' : '' ?></span>
            <span class="badge<?= $membre['role'] === Equipe::GERANT ? ' badge-or' : '' ?>"><?= e(Equipe::ROLES[$membre['role']] ?? $membre['role']) ?></span>
            <span class="ligne-meta"><?= $membre['a_pin'] ? 'Code PIN défini' : 'Pas de code PIN' ?></span>
            <?php if ($membre['email']): ?><span class="ligne-meta"><?= e((string) $membre['email']) ?></span><?php endif; ?>
        </div>
        <div class="ligne-actions">
            <a class="bouton bouton-icone" href="/admin/membre.php?id=<?= $id ?>" aria-label="Modifier <?= e($nomComplet) ?>"><?= icone('crayon', 18) ?></a>
            <?php if ($membre['a_pin']): ?>
                <form method="post">
                    <?= Csrf::champ() ?><input type="hidden" name="id" value="<?= $id ?>">
                    <button class="bouton bouton-discret" name="action" value="supprimer_pin">Supprimer le PIN</button>
                </form>
            <?php endif; ?>
            <?php if ($id !== (int) $utilisateur['id']): ?>
                <form method="post">
                    <?= Csrf::champ() ?><input type="hidden" name="id" value="<?= $id ?>">
                    <button class="bouton" name="action" value="basculer"><?= $membre['actif'] ? 'Désactiver' : 'Réactiver' ?></button>
                </form>
            <?php endif; ?>
        </div>
    </li>
    <?php
};

admin_haut('Équipe', 'equipe');
?>
<div class="entete-page">
    <div>
        <h1>Équipe</h1>
        <p class="sous-titre">Chaque barbier déverrouille la caisse avec son code PIN. Le choix du barbier n'apparaît en caisse que s'il y a plusieurs membres actifs.</p>
    </div>
    <a class="bouton bouton-principal" href="/admin/membre.php"><?= icone('plus', 20) ?>Ajouter un barbier</a>
</div>

<ul class="liste">
    <?php foreach ($actifs as $membre) { $afficher($membre); } ?>
</ul>
<?php if ($inactifs): ?>
    <h2 class="titre-section">Désactivés (<?= count($inactifs) ?>)</h2>
    <ul class="liste">
        <?php foreach ($inactifs as $membre) { $afficher($membre); } ?>
    </ul>
<?php endif; ?>
<?php admin_bas(); ?>
