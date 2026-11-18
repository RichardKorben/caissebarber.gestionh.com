<?php
/**
 * BarberPay — démarrage d'une page d'administration (réservée au gérant connecté) et mise en page commune.
 * Après inclusion : $tenant, $con, $auth, $utilisateur.
 */
declare(strict_types=1);

use BarberPay\Csrf;
use BarberPay\Flash;
use function BarberPay\e;

require __DIR__ . '/web.php';

$utilisateur = $auth->utilisateur();
if ($utilisateur === null) {
    header('Location: /login.php');
    exit;
}
if ($utilisateur['role'] !== 'gerant') {
    bp_page_erreur(403, 'Accès réservé', 'L\'administration est réservée au gérant du salon.');
}

/** Requête POST avec un jeton valide ? Sinon message et redirection vers la même page. */
function admin_post(): bool
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return false;
    }
    if (!Csrf::valide($_POST['csrf'] ?? null)) {
        Flash::erreur('La page a expiré. Recommencez.');
        admin_rediriger($_SERVER['REQUEST_URI'] ?? '/admin/');
    }
    // Les formulaires de l'admin n'envoient que des valeurs simples : on écarte les tableaux (name="x[]").
    $_POST = array_filter($_POST, 'is_string');
    return true;
}

function admin_rediriger(string $url): never
{
    // Uniquement des adresses du site (pas de redirection vers un autre domaine).
    if (!str_starts_with($url, '/') || str_starts_with($url, '//')) {
        $url = '/admin/';
    }
    header('Location: ' . $url, true, 303);
    exit;
}

/** Icônes en trait (style Lucide), décoratives. */
function icone(string $nom, int $taille = 24): string
{
    $traces = [
        'ciseaux' => '<circle cx="6" cy="6" r="3"/><path d="M8.12 8.12 12 12"/><path d="M20 4 8.12 15.88"/><circle cx="6" cy="18" r="3"/><path d="M14.8 14.8 20 20"/>',
        'flacon' => '<path d="M10 2h4"/><path d="M10 2v4l-2 3v12a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V9l-2-3V2"/><path d="M8 13h8"/>',
        'catalogue' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
        'equipe' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'salon' => '<path d="M3 9 4.5 4h15L21 9"/><path d="M4 9v11h16V9"/><path d="M3 9h18"/><path d="M10 20v-5h4v5"/>',
        'caisse' => '<rect x="4" y="2" width="16" height="20" rx="2"/><path d="M8 6h8"/><path d="M8 11h.01M12 11h.01M16 11h.01M8 15h.01M12 15h.01M16 15h.01M8 19h.01M12 19h.01M16 19h.01"/>',
        'sortie' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>',
        'haut' => '<path d="m18 15-6-6-6 6"/>',
        'bas' => '<path d="m6 9 6 6 6-6"/>',
        'plus' => '<path d="M12 5v14"/><path d="M5 12h14"/>',
        'crayon' => '<path d="M17 3a2.85 2.85 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/>',
        'horloge' => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
        'retour' => '<path d="m15 18-6-6 6-6"/>',
    ];
    return '<svg class="icone" width="' . $taille . '" height="' . $taille . '" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
        . ' stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
        . ($traces[$nom] ?? '') . '</svg>';
}

/** Champ de formulaire avec libellé, aide et message d'erreur. */
function champ(string $nom, string $libelle, string $valeur, array $erreurs, array $options = []): string
{
    $type = $options['type'] ?? 'text';
    $id = 'champ-' . $nom;
    $aide = isset($options['aide']) ? '<p class="aide" id="' . $id . '-aide">' . e($options['aide']) . '</p>' : '';
    $erreur = isset($erreurs[$nom]) ? '<p class="erreur-champ" id="' . $id . '-erreur">' . e($erreurs[$nom]) . '</p>' : '';
    $decrit = trim((isset($options['aide']) ? $id . '-aide ' : '') . (isset($erreurs[$nom]) ? $id . '-erreur' : ''));
    $attributs = '';
    foreach (['inputmode', 'autocomplete', 'maxlength', 'placeholder', 'pattern'] as $attribut) {
        if (isset($options[$attribut])) {
            $attributs .= ' ' . $attribut . '="' . e((string) $options[$attribut]) . '"';
        }
    }
    if (!empty($options['requis'])) {
        $attributs .= ' required';
    }
    if ($decrit !== '') {
        $attributs .= ' aria-describedby="' . $decrit . '"';
    }
    if (isset($erreurs[$nom])) {
        $attributs .= ' aria-invalid="true"';
    }
    $suffixe = isset($options['suffixe']) ? '<span class="suffixe">' . e($options['suffixe']) . '</span>' : '';
    return '<div class="champ' . (isset($erreurs[$nom]) ? ' champ-en-erreur' : '') . '">'
        . '<label for="' . $id . '">' . e($libelle) . (!empty($options['requis']) ? ' <span class="requis" aria-hidden="true">*</span>' : '') . '</label>'
        . '<div class="saisie">' . '<input id="' . $id . '" name="' . e($nom) . '" type="' . e($type) . '" value="' . e($valeur) . '"' . $attributs . '>' . $suffixe . '</div>'
        . $aide . $erreur . '</div>';
}

function admin_haut(string $titre, string $actif): void
{
    global $tenant, $utilisateur;
    $liens = [
        'catalogue' => ['/admin/catalogue.php', 'Catalogue', 'catalogue'],
        'equipe' => ['/admin/equipe.php', 'Équipe', 'equipe'],
        'salon' => ['/admin/salon.php', 'Salon', 'salon'],
    ];
    ?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title><?= e($titre) ?> — Admin — <?= e($tenant->nomSalon) ?></title>
    <link rel="stylesheet" href="/public/css/barberpay.css?v=<?= e(BarberPay\VERSION) ?>">
    <link rel="stylesheet" href="/public/css/admin.css?v=<?= e(BarberPay\VERSION) ?>">
</head>
<body class="admin">
<a class="lien-evitement" href="#contenu">Aller au contenu</a>
<div class="admin-cadre">
    <nav class="barre-laterale" aria-label="Administration">
        <a class="logo-lien" href="/" aria-label="Retour à la caisse">
            <span class="logo-poteau" aria-hidden="true">
                <span class="poteau-dome"></span><span class="poteau-embout"></span>
                <span class="poteau-cylindre"></span><span class="poteau-embout"></span>
            </span>
            <span class="marque">ADMIN</span>
        </a>
        <ul class="nav-principale">
            <?php foreach ($liens as $cle => [$url, $libelle, $icone]): ?>
                <li><a class="bouton-nav<?= $cle === $actif ? ' actif' : '' ?>" href="<?= e($url) ?>"<?= $cle === $actif ? ' aria-current="page"' : '' ?>><?= icone($icone, 26) ?><span><?= e($libelle) ?></span></a></li>
            <?php endforeach; ?>
        </ul>
        <div class="nav-outils">
            <a class="bouton-outil" href="/"><?= icone('caisse', 20) ?><span>Caisse</span></a>
            <form method="post" action="/logout.php">
                <?= Csrf::champ() ?>
                <button type="submit" class="bouton-outil"><?= icone('sortie', 20) ?><span>Déconnexion</span></button>
            </form>
        </div>
    </nav>
    <main id="contenu" class="admin-contenu">
        <?php foreach (Flash::lire() as [$type, $message]): ?>
            <p class="flash flash-<?= e($type) ?>" role="<?= $type === 'erreur' ? 'alert' : 'status' ?>"><?= e($message) ?></p>
        <?php endforeach; ?>
    <?php
}

function admin_bas(): void
{
    ?>
    </main>
</div>
</body>
</html>
    <?php
}
