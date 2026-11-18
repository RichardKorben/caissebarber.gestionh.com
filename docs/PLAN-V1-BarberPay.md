# PLAN V1 — BarberPay (caisse barber)

> Cahier des charges de la version 1. Référence pour l'implémentation.
> Rédigé le 18/11/2026. Aucun code n'est écrit à ce stade.
> Documents liés (même dossier) : `DESIGN-caisse-barber.md` (spécification visuelle), `maquette-caisse.png` (maquette).

---

## 1. Objectif

Une caisse tactile **en ligne et gratuite** pour les barbers, en majorité auto-entrepreneurs, conforme aux règles françaises applicables en 2027. L'objectif commercial est de **réunir une base d'utilisateurs** à qui proposer plus tard des offres payantes.

Principes :
- **Simple** : seulement deux familles d'articles, *Services* et *Produits*. Pas de catégories.
- **Aucune donnée client** : pas de fiche client, pas de fidélité, pas d'historique par client.
- **Conforme dès le départ** : ventes impossibles à modifier, chaînage et signature des tickets, clôtures, journal des événements, archivage. Ces règles sont justifiées par une **attestation individuelle de l'éditeur** (art. 125 de la loi de finances 2026, loi n° 2026-103 du 19/02/2026).
- **Un seul code pour tous les barbers** : chaque barber est reconnu par son adresse (`barbermax.gestionh.com`) et a **sa propre base de données**.

---

## 2. Périmètre

### Dans la V1
| Domaine | Contenu |
|---|---|
| Caisse | Services, Produits, montant libre, recherche et scan, ticket, mise en attente, remise, paiement en plusieurs moyens, encaissement |
| Paiement | Carte (saisie manuelle, pas de liaison avec le terminal), espèces avec monnaie à rendre, « autre » (chèque, virement…) |
| Ticket | QR code à l'écran (ticket en ligne), impression navigateur sur demande (format 80 mm), duplicata tracé |
| Annulation | Ticket d'annulation (avoir) lié au ticket d'origine. Jamais de suppression. |
| Équipe | Un ou plusieurs barbiers, code PIN, rôles gérant / barbier. Le choix du barbier n'apparaît que s'il y a plus d'un barbier actif. |
| Caisse du jour | Fond de caisse à l'ouverture, comptage à la fermeture, écart |
| Clôtures | Jour (Z), mois, année, grand total perpétuel |
| Historique | Liste des tickets, détail, duplicata, annulation |
| Recette | Totaux du jour et de la période par moyen de paiement, taux de TVA et barbier |
| Admin | Catalogue, équipe, informations du salon, régime de TVA, exports, attestation, vérification de l'intégrité |
| Exports | Livre des recettes (micro-entrepreneur), récapitulatif URSSAF, export comptable, export pour un contrôle fiscal, totaux e-reporting |
| Franchise de TVA | Mode « TVA non applicable, art. 293 B du CGI » et suivi du seuil de franchise avec alerte |
| Comptes | Inscription libre, création automatique de l'espace du barber, connexion du gérant |

### Hors V1 (plus tard, éventuellement payant)
Fichier client et fidélité, planning et rendez-vous, bons cadeaux, forfaits et cartes prépayées, liaison avec le terminal de paiement (SumUp, Stripe…), ticket par SMS ou e-mail, envoi automatique à une plateforme agréée (e-reporting), fonctionnement sans internet, plusieurs salons, statistiques avancées, pourboires, abonnements payants, certification NF525 par un organisme.

---

## 3. Règles légales et fiscales

### 3.1 Les règles anti-fraude (art. 286 I 3° bis du CGI)
| Règle | Mise en œuvre |
|---|---|
| **Inaltérabilité** | Les tables de ventes ne font qu'**ajouter des lignes**. Des **triggers MariaDB** refusent toute modification ou suppression (`BEFORE UPDATE / DELETE … SIGNAL`). On corrige une erreur par un ticket d'annulation (avoir). |
| **Sécurisation** | Chaque ticket est **chaîné** : son empreinte SHA-256 inclut ses données et l'empreinte du ticket précédent. Elle est **signée en Ed25519** (extension `sodium`) avec une clé propre à chaque barber, rangée hors de la base et hors du dossier web. Même principe pour les clôtures et le journal. |
| **Conservation** | Données gardées **6 ans** au minimum. On n'efface rien, même si le compte est fermé (le compte est seulement gelé). |
| **Archivage** | Une archive est générée à chaque clôture mensuelle et annuelle : un fichier ZIP signé, téléchargeable par le gérant. |
| **Clôtures** | Clôture Z quotidienne (automatique si elle a été oubliée), mensuelle et annuelle. Chacune porte les cumuls et le **grand total perpétuel**, et est chaînée et signée. |
| **Journal des événements** | Ouverture et fermeture de caisse, connexions, annulations, duplicatas, remises, changements de prix, changements de paramètres fiscaux, exports, erreurs d'intégrité. Chaque événement est chaîné et signé. |
| **Numérotation** | Numéro continu, sans trou, attribué **uniquement au moment de l'encaissement**, dans une transaction (`SELECT … FOR UPDATE` sur un compteur). Un ticket en attente ou abandonné n'a pas de numéro. |
| **Mode formation** | Tickets marqués « FORMATION », numérotés à part, exclus des totaux et des clôtures. |
| **Duplicata** | Une réimpression porte la mention « DUPLICATA n° X », et le nombre de duplicatas est enregistré. |
| **Attestation de l'éditeur** | Un PDF généré selon le modèle officiel (BOFiP), avec la version du logiciel. Remis à l'inscription, puis à chaque version importante, et disponible dans l'admin. |

**Leçon tirée de caisse.tactilpay.fr** : sa clôture Z calcule une empreinte SHA-256 des tickets du jour et repère après coup les tickets « modifiés ou supprimés ». La caisse barber doit aller plus loin : **rendre la modification impossible** (triggers), chaîner **chaque ticket** dès sa création, et signer avec une clé (une simple empreinte peut être recalculée par quelqu'un qui a accès à la base).

### 3.2 TVA
- Deux régimes, choisis dans l'admin : **assujetti** (taux par article, 20 % par défaut) ou **franchise** (mention art. 293 B, pas de TVA sur le ticket).
- Un changement de régime est enregistré dans le journal et ne prend effet qu'après une clôture.
- **Seuils de franchise paramétrables**, sans valeur écrite en dur : alerte à 80 %, puis 100 % du seuil, d'après le chiffre d'affaires de l'année civile.
- Calcul : les prix sont **TTC, en centimes entiers**. On calcule la TVA par taux sur le total du ticket : HT = TTC / (1 + taux), arrondi au centime, et TVA = TTC − HT.

### 3.3 Le ticket et la note
Mentions : nom ou raison sociale, adresse, SIRET, date et heure, numéro, détail des lignes (libellé, quantité, prix unitaire, remise), total TTC, TVA par taux **ou** mention 293 B, moyen de paiement, monnaie rendue, barbier, version du logiciel, extrait de la signature.
- Le ticket fait office de **note de prestation obligatoire** à partir de 25 € TTC.
- **Papier seulement sur demande** (loi AGEC). Par défaut, un **QR code** mène à une page publique `/t/<jeton>`. Le jeton est aléatoire (128 bits) et la page n'affiche que ce ticket, sans donnée personnelle.

### 3.4 Facture électronique et e-reporting (septembre 2027 pour les TPE et PME)
- V1 : **export des totaux B2C par jour et par taux de TVA**, que le barber dépose lui-même sur sa plateforme agréée. Le format définitif sera aligné sur les spécifications DGFiP avant septembre 2027.
- Une facture à une entreprise (cas rare) est hors V1 et passera par la plateforme agréée.

### 3.5 RGPD
- Aucune donnée sur les clients des barbers.
- Données personnelles traitées : le compte du gérant et les prénoms des barbiers. Il faut des CGU, des mentions légales, une politique de confidentialité et un contrat de traitement des données (barber ↔ éditeur). Hébergement en France ou dans l'UE.

---

## 4. Architecture

### 4.1 Plusieurs barbers sur un seul code (s'appuie sur le framework kd existant)
- **Code unique** dans `/var/www/gestionh.com/caissebarber.gestionh.com`.
- `app/kdAutoload.php` prend **le début de l'adresse** (`barbermax`) comme identifiant `clientKd` et inclut `app/barbermax/cnx.php`, qui ouvre **la base du barber**.
- **`GH_main`** (base commune) contient le registre des barbers et les noms réservés.
- La clé de signature de chaque barber est rangée dans `/var/kdSite/keys/<clientKd>.key` (droits 600, chiffrée, hors du dossier web). La clé publique est copiée dans le registre et dans les exports.

### 4.2 Adresses web et serveur — **choix retenu : une configuration Apache par barber (option A)**
Même principe que `esthetica-spa.tactilpay.fr` : chaque barber a sa propre configuration Apache (`barbermax.gestionh.com.conf` et `-le-ssl.conf`), avec un `ServerName` précis et un `DocumentRoot` qui pointe vers le code commun `/var/www/gestionh.com/caissebarber.gestionh.com`. Les autres sites ne sont pas touchés.

- **DNS** : rien à faire. La redirection générique `*.gestionh.com` → 212.227.209.6 existe déjà (vérifié le 18/11/2026).
- **Création automatique à l'inscription** : PHP appelle le dispatcher existant (`sudo /usr/bin/python3 /var/kdSite/dispatcher.py <action>`, comme `create_client_dir` dans kdAutoload), avec une nouvelle action `create_barber_site` qui :
  1. vérifie l'identifiant (`^[a-z0-9-]{3,30}$`, pas dans les noms réservés, pas de fichier Apache déjà présent) ;
  2. crée la base, son utilisateur MariaDB et `app/<clientKd>/cnx.php`, puis applique les migrations ;
  3. génère la clé de signature Ed25519 dans `/var/kdSite/keys/` ;
  4. écrit la configuration Apache à partir d'un **modèle**, lance `a2ensite`, puis `apachectl configtest` **avant** `systemctl reload apache2` (une erreur ne doit jamais faire tomber les autres sites) ;
  5. demande le certificat : `certbot --apache -d <clientKd>.gestionh.com --non-interactive` ;
  6. enregistre le barber dans `GH_main` avec le statut de chaque étape. Si une étape échoue, on revient en arrière proprement et une alerte part vers l'admin.
- **Inscription en deux temps** : le compte est créé tout de suite et la page « Ton espace est en préparation… » se met à jour toute seule. Le certificat prend 10 à 30 secondes.
- **Limite Let's Encrypt** : **50 certificats par semaine** pour tout `gestionh.com`, autres sites compris. Ça suffit au lancement. Au-delà, on passera à un certificat générique `*.gestionh.com` (validation DNS) utilisé par toutes les configurations de barbers, toujours une configuration par barber.
- **Fermeture d'un compte** : la configuration est désactivée, mais la base et les archives sont gardées 6 ans (obligation de conservation).
- **Noms réservés** : `www`, `admin`, `api`, `mail`, `install`, `demo`, `caissebarber`, plus **la liste automatique des fichiers présents dans `/etc/apache2/sites-available/`**, pour ne jamais écraser un site existant.

### 4.3 Technique
- PHP 8.3, MariaDB 10.11, mysqli, sodium : tout est déjà installé.
- **Toutes les requêtes en requêtes préparées**. Pas de `addslashes` ni de valeur collée directement dans le SQL.
- Protection CSRF sur toutes les actions, sessions sécurisées (`HttpOnly`, `Secure`, `SameSite`), limite de tentatives sur les PIN et les mots de passe.
- Fuseau horaire `Europe/Paris`. Montants en centimes entiers.
- Interface : HTML, CSS et JavaScript sans framework lourd, conformes au DESIGN.md. Le ticket en cours est tenu côté navigateur et n'est envoyé au serveur qu'à l'encaissement ou à la mise en attente.
- **Mises à jour de structure** : des fichiers SQL numérotés dans `app/migrations/`, une table `schema_version` dans chaque base, et un script `migrer-tous.php` qui applique les migrations à toutes les bases, une par une, en enregistrant les erreurs.

---

## 5. Données

### 5.1 Base de chaque barber
| Table | Rôle | Modifiable |
|---|---|---|
| `parametres` | Salon : nom, adresse, SIRET, régime de TVA, seuils, mentions, logo, options (animation du poteau…) | oui (tracé dans le journal) |
| `utilisateurs` | Gérant et barbiers : prénom, rôle, PIN haché (`password_hash`), couleur, actif | oui |
| `articles` | type `service`/`produit`, nom, prix TTC en centimes, taux de TVA, durée, stock, seuil d'alerte, code-barres, ordre, actif | oui (changements de prix tracés) |
| `tickets_attente` | Tickets en attente (JSON), **sans numéro** | oui |
| `sessions_caisse` | Ouverture et fermeture, fond, comptage, écart | ajout seul |
| `compteurs` | Numéro du dernier ticket, de la dernière clôture… | technique |
| `tickets` | Numéro, type `vente`/`annulation`, ticket d'origine, date, barbier, totaux TTC/HT/TVA, régime, formation, version, `hash_precedent`, `hash`, `signature`, `jeton_public` | **ajout seul** |
| `ticket_lignes` | Libellé et prix **copiés au moment de la vente**, type, quantité, prix unitaire, remise, taux, totaux, barbier | **ajout seul** |
| `ticket_paiements` | Moyen, montant, monnaie rendue | **ajout seul** |
| `duplicatas` | Ticket, date, utilisateur | **ajout seul** |
| `clotures` | Type jour/mois/année, période, numéro, totaux par taux et par moyen, cumuls, grand total perpétuel, premier et dernier ticket, chaînage, signature | **ajout seul** |
| `journal` | Date, code de l'événement, utilisateur, détails JSON, chaînage, signature | **ajout seul** |
| `archives` | Période, fichier, empreinte, signature | **ajout seul** |
| `mouvements_stock` | Article, quantité, motif (vente, annulation, réception, correction) | ajout seul |
| `schema_version` | Version de la structure | technique |

### 5.2 Base commune `GH_main`
- `barber_comptes` : adresse, base, statut (actif, gelé), e-mail du gérant, date d'inscription, version de l'attestation remise, clé publique.
- `barber_noms_reserves`.

---

## 6. Écrans

Visuel : **DESIGN-caisse-barber.md**, avec les ajustements ci-dessous.

### 6.1 Caisse
- **Barre de gauche** : Services, Produits, **Montant libre** ; en bas : Historique, Recette, Admin, **Verrouiller** (remplace « Quitter »). *Planning est retiré.*
- **En-tête du ticket** : « TICKET », **choix du barbier** (affiché seulement s'il y en a plusieurs, à la place de « Client de passage »), bouton pause.
- **Pied du ticket** : **Paiement partiel** (= plusieurs moyens de paiement, jamais de « reste dû ») et **Remise** (à la place de Bon cadeau).
- **Bloc total** : « HT · TVA », ou « TVA non applicable, art. 293 B » en franchise. Corriger le € renvoyé à la ligne (réduire la taille ou mettre HT/TVA sous le total).
- **Vignettes produits** : stock restant à la place de la durée, et produit grisé quand le stock est à 0 (vente possible avec confirmation).
- **Montant libre** : libellé, prix, type service ou produit (pour le taux de TVA), barbier.
- **Remise** : en % ou en €, sur une ligne ou sur le ticket, motif facultatif, enregistrée dans le journal.

### 6.2 Encaissement
Total à payer, moyens de paiement (CB, Espèces, Autre), clavier numérique, montants rapides pour les espèces (billets), monnaie à rendre, plusieurs moyens de paiement, validation. Ensuite : écran « Ticket ? » avec **QR code**, Imprimer et Terminé.

### 6.3 Historique
Tickets du jour et recherche par date, numéro ou montant. Détail, **Duplicata**, **Annuler** (crée un ticket d'annulation après confirmation et PIN du gérant).

### 6.4 Recette et clôture
Ouverture de caisse (fond), totaux en direct, fermeture avec comptage des espèces et écart, **Clôture Z**. Consultation des clôtures mensuelles et annuelles.

### 6.5 Admin (gérant)
Catalogue (ajout, modification, tri par glisser-déposer, désactivation), Équipe, Salon et fiscalité, Exports, Archives, **Vérifier l'intégrité** (recalcule les chaînes et vérifie les signatures), Attestation éditeur (PDF), Mode formation.

### 6.6 Verrouillage
Écran PIN (4 à 6 chiffres) avec la liste des barbiers. Verrouillage automatique après un délai d'inactivité réglable.

### 6.7 Inscription
Page publique : nom du salon, adresse choisie (vérification de disponibilité et des noms réservés), e-mail, mot de passe, SIRET, régime de TVA, acceptation des CGU. Ensuite, création automatique de la base, du dossier, de la clé de signature et de l'adresse, puis un **assistant de démarrage** (premiers services proposés : coupe, dégradé, barbe…).

---

## 7. Exports
| Export | Format | Contenu |
|---|---|---|
| Livre des recettes | PDF, CSV | Date, numéro, nature, montant, moyen de paiement (obligatoire pour les micro-entrepreneurs) |
| Récapitulatif URSSAF | écran, PDF | Chiffre d'affaires du mois ou du trimestre, ventes et services séparés |
| Comptable | CSV | Ventes par jour, par taux et par moyen de paiement |
| Contrôle fiscal | ZIP | Tickets, lignes, paiements, clôtures, journal, clé publique, notice de vérification |
| E-reporting | CSV, JSON | Totaux B2C par jour et par taux (format DGFiP à finaliser) |

---

## 8. Étapes de réalisation

| Étape | Contenu | Terminée quand |
|---|---|---|
| **0. Socle** ✅ (18/11/2026) | Structure des dossiers, intégration kd, système de migrations, registre `GH_main`, base de dev `BARBER_dev`, connexion du gérant | Fait : connexion testée sur `caissebarber.gestionh.com`, migrations passées sur `BARBER_dev` et `BARBER_dev2`. Voir `docs/DEV.md` |
| **1. Catalogue et équipe** ✅ (18/11/2026) | Admin articles, utilisateurs et PIN, paramètres du salon et de la TVA | Fait : `/admin/` (Catalogue, Équipe, Salon), 36 tests de bout en bout passés. Reste pour l'étape 3 : tracer prix et régime de TVA dans le journal |
| **2. Écran caisse** | Interface DESIGN.md avec ajustements, ticket côté navigateur, mise en attente, montant libre, remise | On compose et on met en attente un ticket à la souris et au doigt, en 1280×800 et en 1024 |
| **3. Encaissement et module fiscal** | Numérotation, chaînage, signature, triggers, paiements, ticket QR et impression, annulation, journal | Un ticket encaissé ne peut être ni modifié ni supprimé (testé en SQL direct), la chaîne se vérifie |
| **4. Caisse du jour et clôtures** | Fond, comptage, Z, mois, année, grand total, clôture automatique, historique, duplicata | Une journée complète, ouverture → ventes → annulation → Z, donne des totaux justes et signés |
| **5. Exports et conformité** | Exports §7, archives, vérification d'intégrité, PDF d'attestation, mode formation | Une modification manuelle en base (triggers désactivés) est **détectée** par la vérification |
| **6. Inscription** | Inscription, noms réservés, action `create_barber_site` du dispatcher (base, clé, configuration Apache, certbot), retour en arrière en cas d'échec, CGU, RGPD, assistant | Un nouveau barber s'inscrit et encaisse sur son adresse en moins de 5 minutes, sans intervention |
| **7. Tests et pilote** | Tests des calculs (arrondis, TVA, remises, plusieurs paiements), test de charge sur plusieurs bases, 2 ou 3 barbers pilotes | Retours des pilotes intégrés, attestation v1.0 publiée |

---

## 9. Questions ouvertes
1. ~~Adresses web~~ → **tranché : une configuration Apache par barber sur `gestionh.com`** (§4.2).
2. ~~Nom commercial~~ → **tranché : BarberPay** (attestation de l'éditeur, pied des tickets, page d'inscription).
3. ~~Reprise de caisse.tactilpay.fr~~ → **tranché : on réécrit.** caisse.tactilpay.fr sert de **référence fonctionnelle** (règles d'encaissement, Z, recette, cas particuliers déjà rencontrés). Aucun fichier partagé : le code barber est écrit de zéro, en requêtes préparées et en ajout seul.
4. ~~Plusieurs barbiers~~ → **tranché : oui dès la V1**, choix du barbier masqué s'il n'y en a qu'un.
5. ~~Pourboires~~ → **tranché : hors V1.**
6. ~~Droits d'écriture~~ → **réglé** : ACL pour `richardmaison` sur le projet, documents rangés dans `docs/` (accès web bloqué par `.htaccess`).
