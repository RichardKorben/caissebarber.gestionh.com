# BarberPay — notes de développement

## Organisation des fichiers
```
/                       racine web (caissebarber.gestionh.com et, plus tard, chaque <barber>.gestionh.com)
├── .htaccess           HTTPS forcé, en-têtes de sécurité, fichiers cachés et .md/.sql/.log… refusés
├── index.php           accueil (connecté) — deviendra l'écran de caisse à l'étape 2
├── login.php           connexion du gérant
├── logout.php          déconnexion (POST + jeton uniquement)
├── public/             seul dossier de fichiers statiques : css/, fonts/ (Inter, Oswald hébergées ici)
├── app/                code serveur — accès web refusé (app/.htaccess)
│   ├── bootstrap.php   chargement commun (constantes, autoload BarberPay\…, fuseau, mysqli en exceptions)
│   ├── web.php         démarrage d'une page : barber d'après l'adresse → sa base → session
│   ├── src/            classes : Database, Tenant, Session, Csrf, Auth, Migrator
│   ├── migrations/
│   │   ├── main/       migrations de GH_main (tables barberpay_*)
│   │   └── tenant/     migrations de chaque base de barber
│   └── cli/barberpay   commandes d'administration
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
