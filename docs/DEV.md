# BarberPay — notes de développement

## Organisation des fichiers
```
/                       racine web (caissebarber.gestionh.com et, plus tard, chaque <barber>.gestionh.com)
├── .htaccess           HTTPS forcé, en-têtes de sécurité, fichiers cachés et .md/.sql/.log… refusés
├── index.php           écran de caisse (DESIGN.md)
├── api/caisse.php      actions JSON de la caisse : verrouillage, PIN, tickets en attente
├── login.php           connexion du gérant
├── ticket.php          ticket en ligne /t/<jeton> (sans connexion) et impression 80 mm (?imprimer=1&duplicata=N)
├── historique.php      tickets par jour ou par numéro : voir, réimprimer (duplicata), annuler (gérant)
├── recette.php         recette du jour, ouverture (fond), fermeture avec comptage et Z, liste des clôtures
├── z.php               édition d'une clôture (écran ou 80 mm)
├── verrouiller.php     verrouillage depuis l'Historique / la Recette
├── logout.php          déconnexion (POST + jeton uniquement)
├── admin/              administration (gérant) : catalogue, article, equipe, membre, salon, exports, livre,
│                       telecharger, conformite, attestation
├── public/             fichiers statiques : css/, fonts/ (Inter, Oswald), js/ (calcul.js, caisse.js)
├── app/                code serveur — accès web refusé (app/.htaccess)
│   ├── bootstrap.php   chargement commun (constantes, autoload BarberPay\…, fuseau, mysqli en exceptions)
│   ├── web.php         démarrage d'une page : barber d'après l'adresse → sa base → session
│   ├── admin.php       démarrage d'une page d'admin (gérant obligatoire) + mise en page, champ(), icone()
│   ├── api.php         démarrage d'un point d'accès JSON (session + jeton CSRF en en-tête X-CSRF-Token)
│   ├── poste.php       démarrage des écrans Historique / Recette (caisse déverrouillée) + mise en page
│   ├── admin_outils.php icone(), champ() partagés
│   ├── editeur.php     identité de l'éditeur pour l'attestation (À COMPLÉTER)
│   ├── src/            classes : Database, Tenant, Session, Csrf, Auth, Migrator,
│   │                   Montant, Tva, Flash, Parametres, Articles, Equipe, Calcul, Caisse, TicketsAttente,
│   │                   Ventes, Chaine, Signature, Journal, Integrite, ConflitCatalogue, Clotures, Horloge,
│   │                   Exports, Archives
│   ├── migrations/
│   │   ├── main/       migrations de GH_main (tables barberpay_*)
│   │   └── tenant/     migrations de chaque base de barber
│   └── cli/barberpay   commandes d'administration
├── tests/              tests (run.sh) — accès web refusé
└── docs/               documentation — accès web refusé
```
Les fichiers kd présents dans `app/` (`kdAutoload.php`, `cnx.php`, `caissebarber/cnx.php`…) ne sont pas utilisés par BarberPay.

## Comment un barber est reconnu
1. `app/web.php` lit l'adresse : `barbermax.gestionh.com` → sous-domaine `barbermax`.
2. Il le cherche dans le registre `GH_main.barberpay_comptes` → base `BARBER_barbermax`, nom du salon, statut.
3. Statut `preparation` → page « en préparation », `gele` → « compte suspendu », inconnu → 404.
4. Il ouvre la base du barber et démarre une session liée à ce barber (cookie limité au sous-domaine).

Le registre est la seule source de vérité : il n'y a pas de `cnx.php` par barber. Les identifiants MariaDB
sont ceux du framework kd (`/var/kdSite/bddUser.txt` et `bddMdp.txt`, déchiffrés par `decryptage()`).

## Commandes
```bash
app/cli/barberpay migrer                       # GH_main puis toutes les bases de barbers
app/cli/barberpay migrer --compte=barbermax    # un seul barber
app/cli/barberpay comptes                      # liste du registre
app/cli/barberpay compte:ajouter --sous-domaine=barbermax --base=BARBER_barbermax --salon="Barber Max" --creer-base
BP_MOT_DE_PASSE='…' app/cli/barberpay gerant:creer --compte=barbermax --email=max@exemple.fr --prenom=Max
```
Sans `BP_MOT_DE_PASSE`, un mot de passe est généré et affiché une seule fois.

## Migrations
- Un fichier par changement : `app/migrations/tenant/0002_articles.sql` (4 chiffres, minuscules, `_`).
- **Ne jamais modifier un fichier déjà appliqué** : le lanceur compare son empreinte et s'arrête.
  On écrit une nouvelle migration à la place.
- Les CREATE/ALTER ne sont pas annulables dans MariaDB : utiliser `IF NOT EXISTS` / `IF EXISTS` pour qu'un
  fichier interrompu puisse être rejoué.
- Les triggers s'écrivent sans `DELIMITER` (testé : `BEGIN … END;` passe tel quel).

## Comptes de développement
| Sous-domaine | Base | Rôle |
|---|---|---|
| `caissebarber` | `BARBER_dev` | barber de développement, joignable sur https://caissebarber.gestionh.com |
| `barbertest` | `BARBER_dev2` | 2e base pour tester les migrations (pas d'adresse web) |

## Droits des fichiers
Apache (PHP-FPM, utilisateur `www-data`) doit pouvoir lire les fichiers : groupe `www-data`, droits `g+r`.
Tous les dossiers du projet ont le bit `setgid` (depuis le 18/11/2026, `app/` compris) : un nouveau fichier prend
automatiquement le groupe `www-data`. Vérification rapide :
`find . -path ./.git -prune -o -path ./tests/node_modules -prune -o ! -group www-data -print` (ne doit rien afficher).

## Sécurité
- Toutes les requêtes SQL sont préparées. Aucune valeur venant du navigateur n'est collée dans du SQL.
- Formulaires : jeton CSRF (`Csrf::champ()` / `Csrf::valide()`).
- Affichage : toujours `e()` (échappement HTML).
- Connexion : `password_hash`, blocage après 5 échecs par e-mail ou 10 par IP en 15 minutes.

## Règles métier de l'admin (étape 1)
- **Montants en centimes** (`Montant::analyser('25,50')` → 2550). Taux de TVA en centièmes de % (2000 = 20 %).
- **Un article n'est jamais supprimé**, seulement désactivé (il pourra figurer sur des tickets).
- **Ordre des vignettes** : boutons ↑ ↓ (plus fiables au doigt que le glisser-déposer) ; la liste est renumérotée 1, 2, 3…
- **Équipe** : au moins un gérant actif ; on ne peut pas se désactiver soi-même. PIN de 4 à 6 chiffres,
  refusé s'il est trop simple (1111, 1234…), stocké haché. Un barbier n'a ni e-mail ni mot de passe.
- **Salon** : SIRET vérifié (clé de Luhn). Le nom du salon est recopié dans le registre `GH_main`
  (affiché sur la page de connexion). Seuils de franchise 2026 préremplis, modifiables.

## Tests
```bash
tests/run.sh                                                   # unitaires, calculs PHP = JS, module fiscal
BP_TEST_EMAIL=… BP_TEST_MDP=… tests/run.sh tout                # + API, ticket en ligne, écran (site de dev)
npm install --prefix tests                                     # une fois : jsdom pour l'écran simulé
```
- `tests/fiscal.php` écrit de vrais tickets dans **BARBER_dev2** (compte `barbertest`), jamais ailleurs. Il supprime
  un trigger le temps de prouver qu'une falsification est détectée, puis le remet.
- `tests/journee.php` crée une **base temporaire** (et un compte `test-journee-…` dans le registre), simule plusieurs
  jours avec `Horloge::fixer()`, puis supprime tout (base, compte, clé).
- `tests/api.sh` écrit des tickets de test dans **BARBER_dev** et laisse la caisse de dev verrouillée. Il ne fait
  jamais de Z (un Z bloquerait les ventes de dev pour la journée).

## Écran de caisse (étape 2)
- **Le ticket en cours vit dans le navigateur** (`public/js/caisse.js`, sauvegardé dans `localStorage`). Il n'a
  aucune valeur fiscale ; le serveur refait tous les calculs à l'encaissement.
- **Calcul d'un ticket : deux implémentations identiques**, `app/src/Calcul.php` et `public/js/calcul.js`
  (remises en % ou en €, sur une ligne ou sur le ticket ; remise du ticket répartie au prorata, au centime près ;
  HT et TVA par taux). **Toute modification se fait des deux côtés**, puis on compare les deux sur les mêmes cas.
  Plafond : 99 999,99 € par ticket (calculs exacts en JavaScript, sous 2^53).
- **Opérateur et verrouillage** (`Caisse`) : le gérant ouvre le poste (e-mail + mot de passe). Dès qu'un membre
  actif a un code PIN, la caisse se verrouille après le délai choisi dans Admin → Salon, et chacun la déverrouille
  avec son PIN (blocage après 5 erreurs pendant 15 minutes). L'admin exige qu'un **gérant** soit à la caisse.
  Un gérant sans PIN se reconnecte avec son e-mail depuis l'écran verrouillé.
- **Tickets en attente** : table `tickets_attente`, partagée entre les postes du salon ; le contenu est revérifié
  par le serveur (`TicketsAttente::normaliser`). Reprendre un ticket le retire de l'attente, dans une transaction.
- **Base de données** : les colonnes numériques reviennent en `int` PHP (`MYSQLI_OPT_INT_AND_FLOAT_NATIVE`).
- **Captures** : `docs/captures/`. Rendu avec le Chrome sans interface de Playwright, lancé avec `--no-sandbox`
  (l'outil gstack browse ne démarre pas sur ce serveur à cause du bac à sable de Chrome).

## Module fiscal (étape 3)
- **Tables en ajout seul** : `tickets`, `ticket_lignes`, `ticket_tva`, `ticket_paiements`, `mouvements_stock`, `journal`.
  Des triggers refusent tout `UPDATE` / `DELETE`. Une erreur se corrige par une **annulation** (ticket négatif lié à
  l'original, une seule fois, gérant uniquement).
- **Numérotation** : compteur verrouillé (`SELECT … FOR UPDATE`) pendant l'enregistrement ; numéro attribué
  uniquement à l'encaissement (un ticket en attente n'a pas de numéro).
- **Chaînage et signature** (`Chaine`, `Signature`) : empreinte = SHA-256(empreinte précédente + JSON canonique),
  signée en Ed25519. **Clés : `/var/kdSite/barberpay/cles/<sous-domaine>.cle`** (créées au premier besoin ; à
  sauvegarder : sans la clé, plus de signature possible). `/var/kdSite` car le PHP du site est limité par
  `open_basedir` à `/var/www`, `/tmp`, `/var/kdSite`… La clé publique est copiée dans le registre `GH_main`.
- **Le JSON canonique d'un ticket (`Ventes::canonique`) et d'un événement (`Journal::canonique`) ne doit jamais
  changer** pour les enregistrements existants : sinon le contrôle les déclarerait falsifiés. Tout nouveau champ
  passe par une nouvelle version du format.
- **Identité du salon figée** dans chaque ticket (`tickets.entete`, signée) : un ticket réimprimé garde l'adresse
  et le SIRET du jour de la vente.
- **Le serveur ne croit pas le navigateur** : prix, nom et TVA relus dans le catalogue ; si le total diffère de
  celui affiché, refus (409) ; une clé unique par encaissement empêche le double encaissement si la requête est
  renvoyée.
- **Journal** : connexion, déconnexion, déverrouillage, annulation, création/modification/activation d'article
  (nom, prix, TVA, stock), paramètres du salon, régime de TVA, équipe (jamais les PIN ni les mots de passe).
- **Contrôle** : `app/cli/barberpay integrite --compte=<sous-domaine>` (code de sortie 3 en cas d'anomalie).
- **Ticket en ligne** : `/t/<jeton>` (128 bits aléatoires, aucun cookie posé). Impression : la caisse charge
  `/t/<jeton>?imprimer=1` dans un cadre caché ; régler l'imprimante thermique sur un papier de 80 mm.
- **QR code** : `public/js/vendor/qrcode-generator-1.4.4.min.js` (MIT, copie locale de cdnjs, empreinte et licence
  dans `qrcode-generator.LICENSE.txt`). Vérifié : le QR produit est décodé exactement vers l'adresse du ticket.
  Sans ce fichier, la caisse affiche le lien du ticket.

## Caisse du jour et clôtures (étape 4)
- **Ouverture** (facultative) : fond de caisse du jour (`caisse_ouvertures`, une par jour).
- **Z** (Recette → « Clôturer la journée ») : totaux du jour, par taux, par moyen, services/produits, par barbier,
  remises, espèces attendues / comptées / écart, **grand total perpétuel** (et en valeur absolue). Chaîné et signé
  (compteur `cloture`). **Après le Z, plus aucune vente ni annulation ce jour-là** (contrôlé sous le verrou du
  compteur des tickets).
- **Rattrapage automatique** (`Clotures::rattraper`, appelé à l'ouverture de la caisse, de l'Historique, de la
  Recette et avant chaque encaissement) : Z automatique des journées passées non clôturées, puis clôture
  mensuelle et annuelle (année civile). Possible aussi chaque nuit : `app/cli/barberpay cloturer --tous`.
- **Régime de TVA** : un changement demandé alors que la journée a des ventes attend le Z (`regime_tva_a_venir`) :
  une journée ne mélange jamais deux régimes.
- **Duplicata** : la 1re impression après l'encaissement est l'original ; toute autre impression crée un duplicata
  numéroté (`duplicatas`, journal), et le ticket imprimé porte « DUPLICATA n° X ».
- **Horloge** : les dates du module fiscal passent par `Horloge::date()` ; les tests la fixent, jamais le site.
- Le contrôle d'intégrité vérifie aussi les clôtures : chaînage, signature, et **totaux recalculés à partir des tickets**.

## Exports et conformité (étape 5)
- **Admin → Exports** : livre des recettes (écran imprimable A4 et CSV), journal des ventes (CSV comptable), e-reporting
  B2C (CSV / JSON, **format provisoire** à aligner sur les spécifications DGFiP avant septembre 2027), export pour un
  contrôle fiscal (ZIP : CSV de toutes les tables de la période, clé publique, notice de vérification, résultat du
  contrôle d'intégrité), récapitulatif URSSAF (services / produits, par mois et trimestre), jauges des seuils de
  franchise. Chaque export est inscrit au journal (`EXPORT`). CSV : séparateur « ; », UTF-8 avec BOM (Excel).
- **Archives** : une par clôture mensuelle ou annuelle, créée au premier téléchargement dans
  `/var/kdSite/barberpay/archives/<sous-domaine>/`, empreinte SHA-256 signée dans la table `archives` (ajout seul).
  Une archive modifiée est signalée par le contrôle d'intégrité et son téléchargement est refusé. **À sauvegarder
  avec les clés.**
- **Admin → Conformité** : contrôle d'intégrité (inscrit au journal), archives, attestation, mode formation.
- **Attestation de l'éditeur** (`admin/attestation.php`) : reprend `app/editeur.php`. Tant qu'un champ vaut
  « À COMPLÉTER », elle s'affiche en BROUILLON et ne s'imprime pas. Le texte est à relire contre le modèle officiel
  (BOFiP, BOI-LETTRE-000242) avant la première remise.
- **Mode formation** : par poste (session), activé par le gérant dans Conformité. Tickets sur la chaîne `formation`
  (compteur `ticket_formation`), bandeau orange en caisse et « MODE FORMATION » sur le ticket, exclus des totaux,
  des Z, des exports et du stock, non annulables ; possibles même après le Z. Activation et désactivation au journal.
- **Seuils de franchise** : alerte sur la Recette à 80 % du seuil, puis au dépassement du seuil majoré (année civile).

## Inscription en ligne (étape 6, 18/11/2026)
Parcours : https://barberpay.fr/inscription → SIRET vérifié dans l'annuaire officiel → nom du salon, adresse
`<x>.gestionh.com`, coordonnées, régime de TVA → e-mail de confirmation (lien valable 48 h) → clic sur « Créer ma
caisse maintenant » → création automatique (≈ 15 s) → identifiants envoyés par e-mail (mot de passe généré).

**Fichiers**
- Site (`/var/www/barberpay.fr`, dépôt séparé) : `app/pages/inscription.php`, `app/inscription-api.php` (API
  `/inscription/api`), `httpdocs/assets/js/inscription.js`, `app/secret.php` (clé HMAC, hors git), `tmp/` (fichiers
  de demande, effacés dès leur lecture, hors git). Interrupteur : `inscription_ouverte` dans `app/config.php`.
- Serveur (root, hors git) : `/var/kdSite/scriptsV2/barber_inscription.py` (action `barber_inscription` du
  dispatcher : annuaire, adresse, demande, confirmer, suivi) et `barber_creation.py` (création, lancé en arrière-plan).
  Suivi et paramètres : `/var/kdSite/barberpay/inscriptions/` (700, root). Sauvegarde du dispatcher avant ajout de
  l'action : `/var/backups/dispatcher.py.20261118-152148`.
- Caisse : `app/src/Creation.php` et les commandes `compte:creer` (JSON sur l'entrée standard), `compte:activer`,
  `compte:annuler` (refuse un compte qui n'est pas « en préparation »). Lancées par root en www-data (`runuser`).
- Base commune : `barberpay_inscriptions` (demandes, statut attente → creation → creee | echec | expiree) et
  `barberpay_inscriptions_recherches` (limite de recherches par IP), migration `main/0002_inscriptions.sql`.

**Règles**
- Accepté : SIRET actif, activité coiffure (NAF 96.02A/B) **ou** entrepreneur individuel (nature juridique 1000),
  pas déjà inscrit. Sinon, message avec contact@kdsoft.fr.
- Adresse : `^[a-z][a-z0-9]{2,29}$`, ni réservée (`barberpay_noms_reserves`), ni dans le registre, ni base
  `BARBER_<x>`, ni utilisée par un autre site du serveur (contrôle partagé avec tactilpay : vhosts, certificats…).
- Création : base, registre (statut « preparation »), migrations, gérant, paramètres du salon (identité de
  l'annuaire, TVA intracommunautaire calculée si assujetti), 5 prestations à 0 € masquées (coupe homme, dégradé,
  barbe, coupe + barbe, coupe enfant) ; configuration Apache HTTP (configtest avant rechargement) ; certificat
  Let's Encrypt ; HTTPS + redirection ; contrôle « Espace en préparation » (503) puis, après activation, page de
  connexion (200) ; e-mails (identifiants au gérant, avis à contact@kdsoft.fr).
- Échec à n'importe quelle étape : tout est défait dans l'ordre inverse (vhosts, certificat, base et registre) et un
  avis part à contact@kdsoft.fr. Une seule création à la fois (verrou `/run/lock/barber_creation.lock`).
- Garde-fous : 3 demandes par IP et par e-mail sur 24 h ; 60 recherches dans l'annuaire par IP sur 24 h ;
  10 créations sur 24 h (quota Let's Encrypt de gestionh.com) ; champ piège et délai minimal de 4 s vérifié par un
  horodatage signé (pas de cookie) ; appels d'un autre site refusés (en-tête Origin) ; un seul lancement par lien.

**Test complet du 18/11/2026** : adresse `essaibarber`, SIRET fictif `99999999800001` avec l'annuaire simulé
(`sudo env BP_ANNUAIRE_SIMULE=<fiche.json> python3 barber_inscription.py …`, seulement à la main : le dispatcher ne
transmet pas l'environnement), confirmation par l'API du site : caisse en ligne en 13 s. Un premier essai a échoué
à la vérification HTTPS : le retour arrière a tout supprimé (vhosts, certificat, base, registre). Interface :
15 tests jsdom (formulaire, refus, adresse prise, envoi, suivi, lien expiré).

**Non fait** : liste des inscriptions dans un écran d'admin (lire `GH_main.barberpay_inscriptions`) ; purge des
fichiers de suivi anciens ; certificat générique `*.gestionh.com` au-delà de 50 certificats par semaine.
