# Site barberpay.fr — textes des pages (brouillon v2)

> Rédigé le 18/11/2026 pour relecture du fond. **Le site est codé** dans `/var/www/barberpay.fr` (dépôt séparé) : ce document reste la référence des textes.
> Ton : **tutoiement**, phrases courtes, vocabulaire de salon (pas de jargon comptable sans explication).
> Les passages entre **[À COMPLÉTER]** sont des informations qui manquent encore.
> Les pages légales (CGU, confidentialité) sont des **plans** : à faire relire par un juriste avant la mise en ligne.

## Règles communes à toutes les pages

- Adresse officielle : `https://barberpay.fr` (sans www, sans `.php`, sans slash final).
- Chaque page a : un `<title>` (≈ 60 caractères), une description (≈ 155 caractères), un seul H1, une adresse canonique, une image de partage 1200×630.
- **Ne jamais écrire « certifié » ni « NF525 »** : BarberPay est conforme et fourni avec une **attestation de l'éditeur**.
- **Ne jamais écrire** qu'une caisse devient obligatoire pour tout le monde en 2027 (c'est faux, voir la page Loi).
- Pages de loi : date « Mis à jour le … » visible, liens vers les textes officiels.
- Boutons à la première personne : « Créer mon compte gratuit », « Ouvrir la démo ».
- En-tête (menu) : Fonctionnalités · La loi · Prix · Démo · FAQ · **[Créer mon compte]**
- Pied de page : BarberPay est édité par KDSOFT SAS · Mentions légales · CGU · Confidentialité · Contact · Attestation de conformité

---

## 1. Accueil — `/`

- **Title** : Logiciel de caisse barbier, simple et conforme | BarberPay
- **Description** : La caisse taillée pour les barbiers : on encaisse en deux touches sur tablette, conforme à la loi anti-fraude TVA, prête pour 2027. Et en plus, gratuite.
- **Mots-clés visés** : logiciel de caisse barbier, caisse barbier gratuite, caisse enregistreuse barbier

### Bandeau principal
**H1 : La caisse taillée pour les barbiers.**

Coupes, barbes, produits : tu encaisses en deux touches, sur tablette, ordinateur ou téléphone. Conforme à la loi anti-fraude TVA, livrée avec son attestation, et prête pour l'échéance de septembre 2027.

**[Créer mon compte]**  **[Essayer la démo]**
*Ton compte en 1 minute · Sans carte bancaire · Et en plus, c'est gratuit*

> Choix du 18/11/2026 : l'accent est mis sur « dédié aux barbiers » ; la gratuité est un bonus (« la cerise sur le gâteau »), pas l'accroche. « En un clic » n'est pas écrit tant que l'inscription demande plusieurs champs.

(Visuel : capture de la caisse sur une tablette.)

### Trois arguments
**Pensée pour les barbiers**
Services, produits, c'est tout. Pas de menus à rallonge : tes prestations s'affichent en grandes vignettes, le ticket se remplit à droite, tu encaisses.

**Conforme, sans prise de tête**
Tickets impossibles à modifier, clôtures, archives : tout ce que la loi demande est fait automatiquement. L'attestation de conformité est fournie, gratuitement.

**Gratuit, vraiment**
Pas d'abonnement, pas de limite de tickets, pas de carte bancaire. La caisse est et restera gratuite.

### Es-tu concerné par la loi ?
H2 : Caisse obligatoire, amende, 2027… es-tu concerné ?
Selon que tu factures la TVA ou que tu es auto-entrepreneur, les règles ne sont pas les mêmes. Réponds à deux questions, on te dit ce qui s'applique à toi.
**[Faire le test (30 secondes)]** → `/logiciel-de-caisse-obligatoire#test`

### Comment ça marche
H2 : Ta caisse prête en 3 minutes
1. **Crée ton compte** — le nom de ton salon, ton e-mail, un mot de passe. Ta caisse a sa propre adresse : *ton-salon.gestionh.com*.
2. **Ajoute tes prestations** — coupe, barbe, dégradé… avec leur prix et leur durée. Ajoute tes produits si tu en vends.
3. **Encaisse** — touche une vignette, choisis le moyen de paiement, c'est enregistré. Le client repart avec son ticket par QR code, ou sur papier s'il le demande.

### Ce que tu as dans BarberPay (résumé)
- Encaissement en espèces, carte et autres moyens, avec calcul du rendu de monnaie
- Paiement en plusieurs fois sur un même ticket (ex. : une partie en carte, le reste en espèces)
- Remises en % ou en €
- Plusieurs barbiers, chacun avec son code
- Ticket par QR code, ou imprimé
- Recette du jour, clôtures, historique
- Exports pour ton comptable et pour ta déclaration URSSAF
- Alerte quand tu approches du seuil de la franchise de TVA
**[Voir toutes les fonctionnalités]**

### Bloc démo
H2 : Essaie avant de t'inscrire
Une vraie caisse, avec un faux salon : touche, encaisse, regarde la recette. Rien n'est enregistré pour de vrai.
**[Ouvrir la démo]**

### Questions fréquentes (4 questions reprises de la FAQ)
- C'est vraiment gratuit ?
- Suis-je obligé d'avoir un logiciel de caisse ?
- BarberPay est-il certifié ?
- Faut-il installer quelque chose ?
**[Toutes les questions]**

### Dernier appel
H2 : Prêt à encaisser ?
**[Créer mon compte gratuit]**

---

## 2. Fonctionnalités — `/fonctionnalites`

- **Title** : Fonctionnalités de la caisse BarberPay pour barbiers
- **Description** : Encaissement rapide, plusieurs barbiers, ticket QR code, clôtures, exports comptables et URSSAF : tout ce dont un salon de barbier a besoin, gratuitement.
- **Mots-clés** : caisse enregistreuse barbier, caisse tablette barbier, logiciel salon barbier

**H1 : Tout ce qu'il faut pour encaisser. Rien de plus.**

Intro : BarberPay a été conçu pour les barbiers, à partir de ce qui se passe vraiment au comptoir : un client, une coupe, un paiement. Le reste ne doit pas te ralentir.

### Encaisser
- **Deux onglets : Services et Produits.** Tes prestations en grandes vignettes, avec leur prix et leur durée. Tes produits avec le stock restant.
- **Montant libre** pour une prestation hors catalogue.
- **Recherche et lecteur de code-barres** pour retrouver un produit en une seconde.
- **Remises** en pourcentage ou en euros, sur une ligne ou sur tout le ticket.
- **Tous les moyens de paiement**, et plusieurs sur le même ticket. Le rendu de monnaie est calculé pour toi.
- **Ticket en attente** : un client part chercher sa carte ? Mets le ticket de côté et sers le suivant.

### Le ticket du client
- **Par QR code** : le client scanne et garde son ticket sur son téléphone. Pas d'impression, pas d'e-mail à lui demander.
- **Sur papier** s'il le demande (imprimante ticket 80 mm).
- **Note de prestation** : le ticket contient les mentions obligatoires.

### Ton équipe
- **Plusieurs barbiers** : chaque vente est attribuée au barbier qui a fait la prestation.
- **Un code PIN par personne** : la caisse se verrouille toute seule, chacun la déverrouille avec son code.
- Toi seul (le gérant) as accès à l'administration.

### Tes chiffres
- **Recette du jour** par moyen de paiement et par barbier.
- **Historique** de tous les tickets, réimpression en duplicata.
- **Clôtures** de la journée, du mois et de l'année.
- **Livre des recettes**, à imprimer ou exporter.
- **Totaux pour l'URSSAF** (services et ventes séparés), si tu es micro-entrepreneur.
- **Alerte de seuil** : BarberPay te prévient quand ton chiffre d'affaires approche du seuil de la franchise de TVA.
- **Exports pour ton comptable** (journal des ventes, TVA, règlements).

### Sur tous les écrans
- Fonctionne **dans le navigateur** : tablette, ordinateur ou téléphone. Rien à installer.
- S'ajoute à l'écran d'accueil de ta tablette comme une application.
- Pensé pour le tactile : gros boutons, contraste élevé, lisible toute la journée.

### Conforme
Toutes les règles anti-fraude sont intégrées. → [Comment BarberPay respecte la loi](/attestation-logiciel-de-caisse)

### Ce que BarberPay ne fait pas (encore)
Pas de prise de rendez-vous, pas de fichier client, pas de bons cadeaux. C'est voulu : la caisse reste simple. Des modules complémentaires pourront venir plus tard ; la caisse restera gratuite.

**[Créer mon compte gratuit]**  **[Essayer la démo]**

---

## 3. La loi — `/logiciel-de-caisse-obligatoire`

- **Title** : Logiciel de caisse obligatoire : ce que risque un barbier (2026)
- **Description** : TVA ou franchise, caisse ou pas : qui doit avoir un logiciel de caisse conforme, l'amende de 7 500 €, l'attestation et l'échéance 2027. Le point clair.
- **Mots-clés** : logiciel de caisse obligatoire, amende logiciel de caisse, caisse enregistreuse obligatoire coiffeur barbier
- *Mis à jour le 18 novembre 2026*

**H1 : Logiciel de caisse obligatoire : ce qui s'applique vraiment à ton salon**

Intro : On lit beaucoup de choses sur les caisses « obligatoires » et les amendes. En vrai, tout dépend d'une seule question : **est-ce que tu factures la TVA ?** Voici les règles, en clair.

### Le test (ancre `#test`)
H2 : Es-tu concerné ? Le test en 2 questions

**Question 1 — Est-ce que tu factures la TVA à tes clients ?**
- Oui, mes tickets indiquent de la TVA
- Non, je suis auto-entrepreneur en franchise (mes tickets portent « TVA non applicable, art. 293 B du CGI »)
- Je ne sais pas

**Question 2 (si oui) — Est-ce que tu enregistres tes encaissements dans une caisse, un logiciel ou une application ?**
- Oui
- Non (cahier, carnet à souches…)

**Résultats :**

*TVA + caisse* — **Tu es concerné.** Ton logiciel de caisse doit respecter les règles anti-fraude, et tu dois pouvoir présenter un certificat ou une attestation de l'éditeur. Sinon : **amende de 7 500 € par logiciel**, et 60 jours pour te mettre en règle. BarberPay est conforme et te fournit l'attestation gratuitement. **[Passer à BarberPay]**

*TVA, sans caisse* — La loi ne t'oblige pas à t'équiper d'une caisse. Mais tes recettes doivent pouvoir être justifiées en détail, et **dès que tu utilises une caisse ou une application d'encaissement, elle doit être conforme**. Une caisse gratuite et conforme te simplifie la vie. **[Essayer BarberPay]**

*Franchise (auto-entrepreneur)* — **L'obligation de caisse conforme ne s'applique pas à toi**, tant que tu restes en franchise de TVA. Mais deux choses te concernent :
1. le **1er septembre 2027**, l'**e-reporting** de tes ventes arrive aussi pour les micro-entrepreneurs, franchise comprise → [ce qui change](/e-reporting-2027) ;
2. si ton chiffre d'affaires dépasse le seuil de la franchise, tu factureras la TVA et l'obligation s'appliquera. BarberPay te prévient avant, et tu es déjà équipé.
**[Créer mon compte gratuit]**

*Je ne sais pas* — Regarde un de tes tickets ou une de tes factures : s'il est écrit « TVA non applicable, art. 293 B du CGI », tu es en franchise. S'il y a un montant de TVA, tu factures la TVA. En cas de doute, ton comptable ou ton service des impôts des entreprises peut te répondre.

### Ce que dit la loi
H2 : La règle depuis 2018

Depuis le 1er janvier 2018, une entreprise **assujettie à la TVA** qui encaisse des **particuliers** et qui enregistre ces paiements dans **un logiciel ou un système de caisse** doit utiliser un logiciel qui respecte quatre conditions :
- **inaltérabilité** : une vente enregistrée ne peut plus être modifiée ni effacée ; une erreur se corrige par une annulation visible ;
- **sécurisation** : les données sont protégées contre la falsification ;
- **conservation** : les données sont gardées ;
- **archivage** : des archives périodiques sont produites et conservées.

(Article 286, I, 3° bis du Code général des impôts.)

### Ce qui n'est pas concerné
H2 : Qui n'est pas concerné ?
- Les **micro-entrepreneurs en franchise en base de TVA** (art. 293 B du CGI).
- Les entreprises qui ne vendent qu'à des professionnels.
- Celles qui n'utilisent aucun système de caisse (mais leurs recettes doivent rester justifiées).

(Source : BOFiP, BOI-TVA-DECLA-30-10-30.)

### L'amende
H2 : Quelle amende en cas de contrôle ?
Si tu ne peux pas présenter le certificat ou l'attestation de ton logiciel de caisse lors d'un contrôle, l'amende est de **7 500 € par logiciel ou système de caisse concerné**. Tu as alors **60 jours** pour te mettre en conformité ; sinon, l'amende peut tomber de nouveau.
(Article 1770 duodecies du Code général des impôts.)

### Certificat ou attestation ?
H2 : Certificat ou attestation : quelle différence ?
Pour prouver qu'un logiciel est conforme, il existe deux moyens :
- un **certificat** délivré par un organisme accrédité (par exemple NF525) ;
- une **attestation individuelle de l'éditeur**, par laquelle l'éditeur s'engage sur la conformité de son logiciel.

La loi de finances pour 2025 avait supprimé l'attestation de l'éditeur ; **la loi de finances pour 2026 l'a rétablie** (article 125 de la loi n° 2026-103 du 19 février 2026), depuis le 21 février 2026.
**BarberPay te fournit son attestation, gratuitement, à ton nom.** → [Comment BarberPay respecte la loi](/attestation-logiciel-de-caisse)

### 2027
H2 : Et 2027, alors ?
Ce qui arrive le **1er septembre 2027**, ce n'est pas une nouvelle obligation de caisse : c'est la **facturation électronique et l'e-reporting** pour les petites entreprises, micro-entreprises comprises. Pour un barbier, ça veut surtout dire **transmettre les totaux de ses ventes aux particuliers**. → [Tout comprendre sur l'e-reporting 2027](/e-reporting-2027)

### Sources officielles (liens)
- Article 286 du CGI (Légifrance)
- Article 1770 duodecies du CGI (Légifrance)
- BOFiP BOI-TVA-DECLA-30-10-30
- Loi n° 2026-103 du 19 février 2026 de finances pour 2026, article 125

*Cette page est une information générale, pas un conseil fiscal personnalisé.*

**[Créer mon compte gratuit]**

---

## 4. E-reporting 2027 — `/e-reporting-2027`

- **Title** : E-reporting 2027 : ce qui change pour les barbiers et auto-entrepreneurs
- **Description** : Au 1er septembre 2027, les TPE et micro-entrepreneurs, même en franchise de TVA, devront transmettre leurs ventes aux particuliers. Explications et préparation.
- **Mots-clés** : e-reporting 2027, e-reporting auto-entrepreneur, facturation électronique barbier
- *Mis à jour le 18 novembre 2026*

**H1 : E-reporting 2027 : ce qui change pour ton salon**

Intro : La réforme de la facturation électronique concerne toutes les entreprises, auto-entrepreneurs compris. Pour un barbier, dont les clients sont des particuliers, l'essentiel tient en un mot : **e-reporting**.

### Le calendrier
H2 : Les dates à retenir
- **1er septembre 2026** : toutes les entreprises doivent pouvoir **recevoir** des factures électroniques (celles de tes fournisseurs, par exemple).
- **1er septembre 2027** : les TPE, PME et micro-entreprises doivent **émettre** leurs factures aux professionnels en électronique, et **transmettre les données de leurs ventes aux particuliers** : c'est l'e-reporting.

### Ce que c'est
H2 : L'e-reporting, c'est quoi ?
Quand tu vends à un particulier, tu ne fais pas de facture électronique. À la place, tu transmets à l'administration, régulièrement, **les totaux de tes ventes** (montants, TVA). Pas le détail de chaque client : les totaux.

### Franchise
H2 : Je suis en franchise de TVA, je suis concerné ?
Oui. Ne pas facturer la TVA ne dispense pas de l'e-reporting : les micro-entrepreneurs en franchise sont concernés à partir du 1er septembre 2027.

### Comment ça se transmet
H2 : Par où passent les données ?
Les données sont transmises par une **plateforme agréée** (PA), que tu choisis toi-même ou que ton comptable choisit pour toi.

### Ce que fait BarberPay
H2 : Comment BarberPay te prépare
- BarberPay calcule **les totaux de tes ventes par jour et par taux de TVA**, prêts à être déposés sur ta plateforme agréée ou transmis à ton comptable.
- Le format sera aligné sur les spécifications officielles avant septembre 2027.
- Tes ventes sont déjà enregistrées proprement, jour après jour : le moment venu, il n'y aura rien à reconstituer.

> **À vérifier avant publication** : la périodicité exacte de transmission pour les micro-entreprises, et la mention d'un éventuel envoi direct de BarberPay vers une plateforme (pas prévu en V1 : ne pas le promettre).

### Sources officielles (liens)
- impots.gouv.fr — Facturation électronique et e-reporting
- [À COMPLÉTER : texte de référence à jour]

**[Créer mon compte gratuit]**

---

## 5. Conformité et attestation — `/attestation-logiciel-de-caisse`

- **Title** : Attestation logiciel de caisse : la conformité de BarberPay
- **Description** : Inaltérabilité, sécurisation, conservation, archivage : comment BarberPay respecte la loi anti-fraude TVA, et l'attestation de l'éditeur fournie à chaque salon.
- **Mots-clés** : attestation logiciel de caisse, attestation éditeur, logiciel de caisse conforme
- *Mis à jour le 18 novembre 2026*

**H1 : BarberPay est conforme. Voici comment.**

Intro : La loi demande quatre choses à un logiciel de caisse. BarberPay les fait automatiquement, sans aucun réglage de ta part.

### Les quatre conditions, en pratique
**Inaltérabilité** — Une vente encaissée ne peut plus être modifiée ni supprimée, par personne, pas même nous. Une erreur se corrige par une **annulation** : un ticket négatif, lié au ticket d'origine, qui reste visible.

**Sécurisation** — Chaque ticket est **chaîné** au précédent et **signé électroniquement**. Si une donnée était modifiée, le contrôle d'intégrité le détecterait immédiatement.

**Conservation** — Les clôtures de la journée, du mois et de l'année figent tes totaux. Toutes les opérations sensibles (annulations, remises, changements de réglages) sont inscrites dans un journal.

**Archivage** — Des archives signées sont produites à chaque période et conservées. Tu peux à tout moment télécharger un export fiscal complet pour un contrôle.

### L'attestation
H2 : Ton attestation, à ton nom
Dans ton espace (Admin → Conformité), tu télécharges l'**attestation individuelle de l'éditeur**, établie à ton nom. C'est le document à présenter en cas de contrôle.
- Éditeur : KDSOFT SAS, SIREN 910 135 466
- Base légale : article 286, I, 3° bis du CGI ; loi de finances pour 2026, article 125
**[Voir un exemple d'attestation (PDF)]**

### Mode formation
H2 : T'entraîner sans fausser tes comptes
Le mode formation permet de s'entraîner, toi ou un nouveau barbier : les tickets sont marqués « sans valeur » et ne comptent pas dans ton chiffre d'affaires.

### Pas de certification NF525 ?
H2 : Pourquoi une attestation et pas un certificat NF525 ?
La loi admet les deux. Une certification par un organisme coûte cher à l'éditeur, et ce coût finit en général dans l'abonnement. L'attestation de l'éditeur, rétablie en 2026, permet elle aussi de prouver la conformité, et nous permet de garder BarberPay gratuit.

*(À relire : vérifier que l'attestation a bien la même portée que le certificat au regard de l'article 286 dans sa rédaction issue de la loi de finances 2026.)*

**[Créer mon compte gratuit]**

---

## 6. Prix — `/prix`

> Renommée le 18/11/2026 (ancienne adresse `/gratuit`, redirigée en 301) : le menu dit « Prix », la page répond « gratuit ».

- **Title** : Prix du logiciel de caisse barbier : gratuit | BarberPay
- **Description** : Combien coûte BarberPay ? Rien : pas d'abonnement, pas de carte bancaire, pas de limite de tickets. Seuls d'éventuels modules complémentaires seront payants.
- **Mots-clés** : logiciel de caisse gratuit, caisse gratuite barbier, caisse enregistreuse gratuite

**H1 : Gratuit. Pas pour un mois : tout le temps.**

### Ce qui est gratuit
Toute la caisse :
- encaissement, tickets, QR code, impression ;
- plusieurs barbiers ;
- clôtures, historique, recette ;
- exports comptables et URSSAF ;
- conformité et attestation ;
- mises à jour.

Pas d'abonnement, pas de carte bancaire demandée, pas de limite de tickets ni de durée.

### Et plus tard ?
H2 : Pourquoi c'est gratuit, et ce qui pourra être payant
On veut que chaque barbier puisse avoir une caisse conforme sans payer d'abonnement. Plus tard, on proposera peut-être des **modules complémentaires optionnels** (par exemple : prise de rendez-vous). Ils seront payants et facultatifs. **La caisse, elle, restera gratuite.**

### Matériel
H2 : Et le matériel ?
BarberPay fonctionne sur la tablette, l'ordinateur ou le téléphone que tu as déjà, avec une connexion internet. Une imprimante ticket est facultative.

**[Créer mon compte gratuit]**

---

## 7. Démo — `/demo`

- **Title** : Démo gratuite de la caisse BarberPay
- **Description** : Essaie la caisse BarberPay sans inscription : un faux salon, de vraies fonctions. Encaisse, regarde la recette, explore l'administration.

**H1 : Essaie la caisse, sans t'inscrire**

Un salon de démonstration t'attend : prestations, produits, deux barbiers et quelques ventes déjà passées.
- Encaisse une coupe, un produit, un paiement en deux fois.
- Regarde la recette du jour et l'historique.
- Fais un tour dans l'administration.

Les tickets de la démo sont **sans valeur** et la démo est remise à zéro chaque nuit. Rien de ce que tu tapes n'est conservé.

**[Ouvrir la démo]** → `https://barberdemo.gestionh.com`
*Astuce : sur tablette, tourne l'écran à l'horizontale.*

Ça te plaît ? **[Créer mon compte gratuit]**

(Dans la démo, un bandeau permanent : « Tu es dans la démo — [Créer mon compte gratuit] ».)

---

## 8. Inscription — `/inscription`

- **Title** : Créer mon compte BarberPay gratuit
- **Description** : Crée ta caisse BarberPay en 3 minutes : gratuite, sans carte bancaire, sans engagement.

**H1 : Crée ta caisse gratuite**

Champs :
- **Nom du salon** — *ex. : Barber Max*
- **Adresse de ta caisse** — `[barbermax].gestionh.com` (proposée à partir du nom, modifiable ; on vérifie en direct qu'elle est libre)
- **Ton e-mail** — *pour te connecter et recevoir les informations importantes*
- **Mot de passe**
- Case : « J'accepte les [conditions d'utilisation] et j'ai lu la [politique de confidentialité]. »

**[Créer ma caisse]**

Sous le bouton : *Gratuit · Sans carte bancaire · Tu pourras ajouter tes prestations juste après.*

Page d'attente : **Ta caisse est en préparation…** (mise à jour automatique) — puis : **C'est prêt ! Ta caisse : barbermax.gestionh.com — [Ouvrir ma caisse]**. Conseil : ajoute cette adresse à l'écran d'accueil de ta tablette.

---

## 9. FAQ — `/faq`

- **Title** : Questions fréquentes sur la caisse BarberPay
- **Description** : Gratuité, obligation légale, attestation, matériel, données : les réponses aux questions des barbiers sur BarberPay.

**H1 : Questions fréquentes**

**C'est vraiment gratuit ?**
Oui. La caisse est gratuite, sans abonnement et sans limite. Des modules complémentaires optionnels pourront être payants plus tard ; la caisse restera gratuite.

**Suis-je obligé d'avoir un logiciel de caisse ?**
Si tu factures la TVA et que tu enregistres tes encaissements dans une caisse ou une application, elle doit être conforme. Si tu es auto-entrepreneur en franchise de TVA, cette obligation ne s'applique pas. [Fais le test](/logiciel-de-caisse-obligatoire#test).

**Quelle amende si ma caisse n'est pas conforme ?**
7 500 € par logiciel ou système de caisse, si tu ne peux pas présenter de certificat ou d'attestation lors d'un contrôle, avec 60 jours pour régulariser.

**BarberPay est-il certifié NF525 ?**
Non. BarberPay est conforme et livré avec une attestation de l'éditeur, que la loi admet de nouveau depuis le 21 février 2026. C'est elle que tu présentes en cas de contrôle.

**Je suis auto-entrepreneur : BarberPay me sert à quoi ?**
Livre des recettes, totaux pour l'URSSAF, alerte avant le seuil de la franchise, et préparation à l'e-reporting de septembre 2027.

**Faut-il installer quelque chose ?**
Non. BarberPay fonctionne dans le navigateur de ta tablette, ton ordinateur ou ton téléphone. Il faut une connexion internet.

**Je peux imprimer les tickets ?**
Oui, avec une imprimante ticket 80 mm. Mais par défaut, le client reçoit son ticket par QR code : pas de papier, comme le veut la loi anti-gaspillage.

**Plusieurs barbiers peuvent utiliser la même caisse ?**
Oui. Chaque barbier a son code, et chaque vente est attribuée à celui qui a fait la prestation.

**Mes clients sont enregistrés ?**
Non. BarberPay n'enregistre aucune donnée sur tes clients : pas de nom, pas de téléphone, pas d'e-mail.

**Où sont mes données ? Qui y a accès ?**
Tes données sont hébergées en France, chez IONOS. Chaque salon a sa propre base de données. Tes données de ventes t'appartiennent ; tu peux les exporter à tout moment.

**Et si j'arrête ?**
Tu peux fermer ton compte quand tu veux. La loi impose de conserver les données de caisse pendant 6 ans : elles restent archivées et tu peux en obtenir une copie.

**Mon comptable peut récupérer mes chiffres ?**
Oui : journal des ventes, TVA, règlements, livre des recettes, en CSV, à télécharger depuis l'administration.

**Vous gérez la prise de rendez-vous ?**
Pas pour l'instant. BarberPay se concentre sur la caisse.

---

## 10. Contact — `/contact`

- **Title** : Contacter BarberPay
**H1 : Une question ? Écris-nous.**
Formulaire : nom, e-mail, salon (facultatif), message.
Ou par e-mail : **contact@kdsoft.fr**
*On te répond sous 48 h ouvrées.*

---

## 11. Mentions légales — `/mentions-legales`

(Les pages légales restent en style neutre, sans tutoiement.)

**H1 : Mentions légales**

**Éditeur du site et du logiciel BarberPay**
KDSOFT, société par actions simplifiée au capital de 2 000 €
Siège social : 8 rue de la Limagne, 63360 Saint-Beauzire
SIREN 910 135 466 — RCS Clermont-Ferrand *(greffe à confirmer sur l'extrait Kbis)*
SIRET du siège : 910 135 466 00019
N° de TVA intracommunautaire : FR67910135466
Code APE : 5829C (édition de logiciels applicatifs)
Présidente : HOLDING LDP
Directeur de la publication : Loic PAPON
Contact : contact@kdsoft.fr

**Hébergeur**
IONOS SARL — 7 place de la Gare, BP 70109, 57200 Sarreguemines Cedex — France
*(coordonnées à recopier depuis le contrat IONOS pour être sûr)*

**Propriété intellectuelle** — Le nom BarberPay, le logo et les contenus du site sont la propriété de KDSOFT SAS.

---

## 12. CGU — `/cgu` (plan à faire relire)

1. Objet : mise à disposition gratuite du logiciel de caisse BarberPay.
2. Création du compte, exactitude des informations, sécurité du mot de passe et des codes PIN.
3. Gratuité de la caisse ; modules complémentaires éventuels régis par des conditions particulières.
4. Obligations de l'utilisateur : usage conforme, déclaration de son régime de TVA exact, responsabilité de ses déclarations fiscales.
5. Conformité : engagements de l'éditeur (attestation), limites.
6. Disponibilité du service, maintenance, connexion internet nécessaire.
7. Données : propriété des données de ventes, exports, conservation 6 ans, fermeture du compte.
8. Responsabilité.
9. Modification des CGU.
10. Droit applicable, juridiction.

## 13. Confidentialité — `/confidentialite` (plan à faire relire)

1. Responsable de traitement : KDSOFT SAS (comptes des gérants, site) ; pour les données de ventes, le salon est responsable et KDSOFT sous-traitant → contrat de sous-traitance (art. 28 RGPD).
2. Données traitées : e-mail et mot de passe (chiffré) du gérant, prénoms des barbiers, données de ventes. **Aucune donnée sur les clients des salons.**
3. Finalités et bases légales.
4. Durées de conservation (ventes : 6 ans, obligation légale).
5. Hébergement : en France, chez IONOS.
6. Mesure d'audience sans cookie de suivi (Matomo exempté de consentement) : pas de bandeau cookies.
7. Droits (accès, rectification, effacement…), contact, recours CNIL.

---

## Données structurées (rappel pour le code)

- Accueil : `SoftwareApplication` (nom BarberPay, catégorie BusinessApplication, système « Web », offre à 0 EUR) + `Organization` (KDSOFT SAS, logo).
- FAQ : `FAQPage` avec les questions de la page.
- Toutes les pages sauf l'accueil : `BreadcrumbList`.

## À décider / à vérifier avant mise en ligne

1. ~~Nom de la démo~~ → **barberdemo.gestionh.com** (validé le 18/11/2026).
2. ~~E-mail de contact~~ → **contact@kdsoft.fr** (validé le 18/11/2026). Délai de réponse : 48 h ouvrées (validé le 18/11/2026).
3. Mentions légales : ville du greffe (RCS) à confirmer ; coordonnées d'IONOS à recopier du contrat. Directeur de publication (Loic PAPON) validé le 18/11/2026.
4. ~~Lieu d'hébergement~~ → **IONOS, France** (validé le 18/11/2026). Vérifier dans l'espace IONOS que le serveur est bien dans un centre de données en France, puisque la FAQ l'affirme.
5. ~~E-reporting~~ → vérifié le 18/11/2026 dans la FAQ officielle impots.gouv.fr « Je découvre la facturation électronique » (version du 01/09/2026) : en franchise en base, transmission **tous les deux mois** (réel normal mensuel : trois fois par mois) ; montant total des opérations par jour ; par une plateforme agréée ; pas besoin d'acheter un logiciel (Q. 7.1, 8.1, 9.1 à 9.3).
6. ~~Portée de l'attestation~~ → vérifié le 18/11/2026 dans le BOFiP BOI-TVA-DECLA-30-10-30 (version du 25/03/2026) : « mode de preuve alternatif : un seul de ces deux documents (certificat ou attestation individuelle) suffit ». À surveiller : l'article 286 du CGI a une nouvelle version depuis le 27/06/2026 (contenu non vérifié).
7. Relecture juridique des CGU, de la confidentialité et du contrat de sous-traitance.
