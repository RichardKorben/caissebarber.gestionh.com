# BarberPay — notes de développement

## Organisation des fichiers
```
/                       racine web (caissebarber.gestionh.com et, plus tard, chaque <barber>.gestionh.com)
├── .htaccess           HTTPS forcé, en-têtes de sécurité, fichiers cachés et .md/.sql/.log… refusés
├── index.php           écran de caisse (DESIGN.md)
├── api/caisse.php      actions JSON de la caisse : verrouillage, PIN, tickets en attente
├── login.php           connexion du gérant
├── ticket.php          ticket en ligne /t/<jeton> (sans connexion) et impression 80 mm (?imprimer=1)
├── logout.php          déconnexion (POST + jeton uniquement)
├── admin/              administration (gérant) : catalogue.php, article.php, equipe.php, membre.php, salon.php
├── public/             fichiers statiques : css/, fonts/ (Inter, Oswald), js/ (calcul.js, caisse.js)
├── app/                code serveur — accès web refusé (app/.htaccess)
│   ├── bootstrap.php   chargement commun (constantes, autoload BarberPay\…, fuseau, mysqli en exceptions)
│   ├── web.php         démarrage d'une page : barber d'après l'adresse → sa base → session
│   ├── admin.php       démarrage d'une page d'admin (gérant obligatoire) + mise en page, champ(), icone()
│   ├── api.php         démarrage d'un point d'accès JSON (session + jeton CSRF en en-tête X-CSRF-Token)
│   ├── src/            classes : Database, Tenant, Session, Csrf, Auth, Migrator,
│   │                   Montant, Tva, Flash, Parametres, Articles, Equipe, Calcul, Caisse, TicketsAttente,
│   │                   Ventes, Chaine, Signature, Journal, Integrite, ConflitCatalogue
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
Le dossier `app/` appartient à `root` et n'a pas le bit `setgid` : un nouveau fichier créé **directement**
dans `app/` prend le mauvais groupe. Après en avoir créé un : `chgrp www-data app/<fichier>`.
(Les sous-dossiers `src/`, `cli/`, `migrations/` ont le `setgid` et n'ont pas ce problème.)

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
- `tests/api.sh` écrit des tickets de test dans **BARBER_dev** et laisse la caisse de dev verrouillée.

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
- **QR code** : la caisse charge `public/js/vendor/qrcode-generator-1.4.4.min.js` s'il existe (bibliothèque MIT,
  non encore ajoutée) ; sinon elle affiche le lien du ticket.
