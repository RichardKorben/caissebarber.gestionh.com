<?php
/**
 * BarberPay — écran de caisse (DESIGN.md).
 * Le serveur fournit le catalogue, l'équipe et les paramètres ; le ticket est composé dans le navigateur
 * (public/js/caisse.js) et ne repart au serveur qu'à la mise en attente ou à l'encaissement.
 */
declare(strict_types=1);

use BarberPay\Articles;
use BarberPay\Caisse;
use BarberPay\Clotures;
use BarberPay\Csrf;
use BarberPay\Parametres;
use BarberPay\TicketsAttente;
use function BarberPay\e;

require __DIR__ . '/app/web.php';

if ($auth->utilisateur() === null) {
    header('Location: /login.php');
    exit;
}

$parametres = (new Parametres($con, $tenant))->charger();
$caisse = new Caisse($con, (int) $parametres['verrouillage_minutes']);
$clotures = new Clotures($con, $tenant, $journal);
$clotures->rattraper(); // journées oubliées clôturées automatiquement
$parametres = (new Parametres($con, $tenant))->charger(); // le régime de TVA a pu changer à la clôture
$operateur = $caisse->operateur();
$articles = new Articles($con);

$catalogue = [];
foreach ([Articles::SERVICE, Articles::PRODUIT] as $type) {
    foreach ($articles->lister($type, false) as $a) {
        $catalogue[] = [
            'id' => (int) $a['id'],
            'type' => $a['type'],
            'nom' => $a['nom'],
            'prix' => (int) $a['prix_ttc'],
            'taux' => (int) $a['taux_tva'],
            'duree' => $a['duree_minutes'] !== null ? (int) $a['duree_minutes'] : null,
            'stock' => $a['stock'] !== null ? (int) $a['stock'] : null,
            'codeBarre' => $a['code_barre'],
        ];
    }
}
$membres = array_map(static fn (array $m): array => [
    'id' => (int) $m['id'],
    'prenom' => $m['prenom'],
    'role' => $m['role'],
    'couleur' => $m['couleur'] ?? '#A0A0A0',
    'aPin' => (bool) $m['a_pin'],
], $caisse->membres());

$donnees = [
    'salon' => $tenant->sousDomaine,
    'franchise' => $parametres['regime_tva'] === Parametres::FRANCHISE,
    'verrouillage' => $caisse->verrouillageActif(),
    'verrouillageMinutes' => (int) $parametres['verrouillage_minutes'],
    'operateur' => $operateur ? ['id' => (int) $operateur['id'], 'prenom' => $operateur['prenom'], 'role' => $operateur['role']] : null,
    'membres' => $membres,
    'articles' => $catalogue,
    'attente' => (new TicketsAttente($con))->nombre(),
    'journeeCloturee' => $clotures->journeeCloturee(date('Y-m-d')),
    'formation' => !empty($_SESSION['formation']),
];
$animation = (int) $parametres['animation_poteau'] === 1;

/** Icônes en trait (style Lucide), décoratives. */
function icone(string $nom, int $taille = 24): string
{
    $traces = [
        'ciseaux' => '<circle cx="6" cy="6" r="3"/><path d="M8.12 8.12 12 12"/><path d="M20 4 8.12 15.88"/><circle cx="6" cy="18" r="3"/><path d="M14.8 14.8 20 20"/>',
        'flacon' => '<path d="M10 2h4"/><path d="M10 2v4l-2 3v12a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V9l-2-3V2"/><path d="M8 13h8"/>',
        'libre' => '<rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/><path d="M6 12h.01M18 12h.01"/>',
        'historique' => '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l4 2"/>',
        'recette' => '<path d="M3 3v18h18"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/>',
        'admin' => '<path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/>',
        'cadenas' => '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
        'loupe' => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
        'pause' => '<rect x="6" y="4" width="4" height="16" rx="1"/><rect x="14" y="4" width="4" height="16" rx="1"/>',
        'carte' => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/>',
        'remise' => '<path d="M19 5 5 19"/><circle cx="6.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/>',
        'fleche' => '<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>',
    ];
    return '<svg class="icone" width="' . $taille . '" height="' . $taille . '" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
        . ' stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . ($traces[$nom] ?? '') . '</svg>';
}
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex">
    <meta name="csrf" content="<?= e(Csrf::jeton()) ?>">
    <?= BarberPay\icones_tete() ?>
    <title>Caisse — <?= e($tenant->nomSalon) ?></title>
    <link rel="stylesheet" href="/public/css/barberpay.css?v=<?= e(BarberPay\VERSION) ?>">
    <link rel="stylesheet" href="/public/css/caisse.css?v=<?= e(BarberPay\VERSION) ?>">
</head>
<body class="caisse">
<?php if (!empty($_SESSION['formation'])): ?>
<div class="bandeau-formation" role="status">MODE FORMATION — tickets sans valeur, non comptabilisés. <a href="/admin/conformite.php">Quitter</a></div>
<?php elseif ($tenant->demo): ?>
<?php require __DIR__ . '/app/demo-bandeau.php'; ?>
<?php endif; ?>
<div class="app<?= !empty($_SESSION['formation']) ? ' app-formation' : ($tenant->demo ? ' app-demo' : '') ?>" id="app">
    <!-- 1. Barre latérale -->
    <nav class="barre" aria-label="Caisse">
        <div class="logo" aria-hidden="true">
            <?= BarberPay\embleme(false, $animation) ?>
            <?= BarberPay\logotype() ?>
        </div>
        <div class="categories">
            <button type="button" class="bouton-categorie" data-categorie="service" aria-pressed="true"><?= icone('ciseaux', 26) ?><span>Services</span></button>
            <button type="button" class="bouton-categorie" data-categorie="produit" aria-pressed="false"><?= icone('flacon', 26) ?><span>Produits</span></button>
            <button type="button" class="bouton-categorie" data-action="montant-libre"><?= icone('libre', 26) ?><span>Montant libre</span></button>
        </div>
        <div class="outils">
            <a class="bouton-outil" href="/historique.php"><?= icone('historique', 20) ?><span>Historique</span></a>
            <a class="bouton-outil" href="/recette.php"><?= icone('recette', 20) ?><span>Recette</span></a>
            <a class="bouton-outil" href="/admin/" id="lien-admin"><?= icone('admin', 20) ?><span>Admin</span></a>
            <button type="button" class="bouton-outil" data-action="verrouiller" id="bouton-verrouiller"><?= icone('cadenas', 20) ?><span>Verrouiller</span></button>
        </div>
    </nav>

    <!-- 2. Liste des articles -->
    <main class="liste" aria-labelledby="titre-categorie">
        <header class="liste-entete">
            <div>
                <h1 id="titre-categorie">Services</h1>
                <p class="compteur" id="compteur-categorie"></p>
            </div>
            <div class="recherche">
                <label for="recherche" class="visuellement-cache">Rechercher un article ou scanner un code-barres</label>
                <?= icone('loupe', 20) ?>
                <input type="search" id="recherche" placeholder="Rechercher ou scanner…" autocomplete="off" enterkeyhint="search">
            </div>
        </header>
        <div class="grille" id="grille"></div>
        <p class="aucun" id="aucun" hidden></p>
    </main>

    <!-- 3. Ticket -->
    <aside class="caisse-panneau" aria-label="Ticket en cours">
        <div class="ticket">
            <div class="liseret" aria-hidden="true"></div>
            <header class="ticket-entete">
                <h2>Ticket</h2>
                <div class="ticket-outils">
                    <label class="choix-barbier" id="bloc-barbier" hidden>
                        <span class="visuellement-cache">Barbier du ticket</span>
                        <span class="pastille" id="pastille-barbier" aria-hidden="true"></span>
                        <select id="barbier"></select>
                    </label>
                    <button type="button" class="bouton-pause" id="bouton-attente" aria-label="Mettre le ticket en attente">
                        <?= icone('pause', 18) ?><span class="badge-attente" id="badge-attente" hidden></span>
                    </button>
                </div>
            </header>
            <div class="ticket-lignes" id="lignes" aria-live="polite"></div>
            <div class="ticket-vide" id="ticket-vide">
                <?= icone('ciseaux', 40) ?>
                <p class="vide-titre">Ticket vide</p>
                <p>Touchez un service ou un produit pour l'ajouter.</p>
            </div>
            <footer class="ticket-pied">
                <div class="pied-boutons">
                    <button type="button" class="bouton-clair" data-action="paiement-partiel"><?= icone('carte', 20) ?>Partiel</button>
                    <button type="button" class="bouton-clair" data-action="remise-ticket" id="bouton-remise"><?= icone('remise', 20) ?>Remise</button>
                </div>
                <div class="bloc-total">
                    <div class="total-details">
                        <span id="total-articles">0 article</span>
                        <span id="total-remise" hidden></span>
                        <span id="total-tva"></span>
                    </div>
                    <output class="total-montant" id="total" aria-live="polite">0,00 €</output>
                </div>
                <button type="button" class="bouton-encaisser" id="bouton-encaisser" data-action="encaisser">Encaisser <?= icone('fleche', 24) ?></button>
            </footer>
        </div>
    </aside>
</div>

<!-- Fenêtres (dialog) remplies par caisse.js -->
<dialog class="fenetre" id="fenetre" aria-labelledby="fenetre-titre">
    <form method="dialog" class="fenetre-contenu" id="fenetre-contenu"></form>
</dialog>

<!-- Écran de verrouillage -->
<div class="verrou" id="verrou" hidden role="dialog" aria-modal="true" aria-labelledby="verrou-titre">
    <div class="verrou-boite">
        <div class="logo" aria-hidden="true">
            <?= BarberPay\embleme(true, $animation) ?>
        </div>
        <h2 id="verrou-titre"><?= e($tenant->nomSalon) ?></h2>
        <p class="verrou-consigne" id="verrou-consigne">Qui est à la caisse ?</p>
<?php if ($tenant->demo): ?>
        <p class="verrou-astuce">Démo : codes PIN <?= e(BarberPay\Demo::astucePins()) ?></p>
<?php endif; ?>
        <div class="verrou-membres" id="verrou-membres"></div>
        <div class="verrou-pin" id="verrou-pin" hidden>
            <p class="verrou-points" id="verrou-points" aria-live="polite"></p>
            <p class="verrou-erreur" id="verrou-erreur" role="alert"></p>
            <div class="pave" id="verrou-pave"></div>
            <button type="button" class="bouton-lien" id="verrou-retour">Changer de personne</button>
        </div>
        <form method="post" action="/logout.php">
            <?= Csrf::champ() ?>
            <button type="submit" class="bouton-lien">Pas de code ? Se reconnecter avec l'e-mail du gérant</button>
        </form>
    </div>
</div>

<div class="toast" id="toast" role="status" aria-live="polite" hidden></div>

<script>window.BARBERPAY = <?= json_encode($donnees, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<?php if (is_file(__DIR__ . '/public/js/vendor/qrcode-generator-1.4.4.min.js')): ?>
<script src="/public/js/vendor/qrcode-generator-1.4.4.min.js"></script>
<?php endif; ?>
<script src="/public/js/calcul.js?v=<?= e(BarberPay\VERSION) ?>"></script>
<script src="/public/js/caisse.js?v=<?= e(BarberPay\VERSION) ?>"></script>
</body>
</html>
