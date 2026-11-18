# DESIGN.md — Caisse Barber (écran principal)

> Spécification visuelle et fonctionnelle de l'écran de caisse pour barbiers.
> À utiliser comme référence par Claude Code pour implémenter l'interface.
> Maquette de référence : canvas « Caisse Barber » (https://claude.ai/artifact/82GzXT8JhQukNucK7froWw).

---

## 1. Intention

- Caisse tactile pour **petits barbiers** (homme / barbe), dérivée de l'appli coiffeur existante, mais **simplifiée** : seulement **2 catégories** — *Services* et *Produits*.
- Les 4 blocs de l'appli coiffeur (grille articles / recherche / onglets / grille de familles) sont remplacés par **3 colonnes** :
  1. **Barre latérale verticale** (choix Services / Produits + outils)
  2. **Liste des articles** de la catégorie active
  3. **Caisse** (ticket + encaissement)
- Ambiance : **noir, or, blanc**, avec une touche du **poteau de barbier** (rayures bleu / blanc / rouge).
- Usage : tablette ou écran tactile en paysage, toute la journée → contraste élevé, cibles tactiles ≥ 44 px.

---

## 2. Design tokens

```css
:root {
  /* Fonds */
  --bg-app:        #121212;  /* fond général (colonne liste) */
  --bg-sidebar:    #0A0A0A;  /* barre latérale */
  --bg-card:       #1B1B1B;  /* cartes articles, champ recherche */
  --bg-card-hover: #242424;
  --border-dark:   #2E2E2E;  /* bordures sur fond sombre */
  --border-side:   #232323;  /* séparateur barre latérale */

  /* Panneau ticket (clair) */
  --bg-ticket:     #FFFFFF;
  --bg-ticket-btn: #F6F6F6;  /* boutons -/+ */
  --border-light:  #DCDCDC;  /* bordures boutons sur blanc */
  --divider-light: #ECECEC;
  --row-divider:   #F0F0F0;
  --bg-total:      #0E0E0E;  /* bloc total noir dans le ticket */

  /* Texte */
  --text-on-dark:        #FFFFFF;
  --text-on-dark-2:      #F5F5F5;
  --text-muted-dark:     #9A9A9A; /* méta (durée, stock) sur fond sombre */
  --text-side:           #A8A8A8; /* libellés outils barre latérale */
  --text-side-inactive:  #D8D8D8; /* Services/Produits non actif */
  --text-on-light:       #111111;
  --text-muted-light:    #666666;
  --text-on-gold:        #0A0A0A;

  /* Accent */
  --gold:        #C9A44C;  /* accent principal (variantes possibles : #D4AF37, #B8935A) */
  --gold-hover:  #E2C57A;

  /* Poteau de barbier */
  --pole-red:   #C8102E;
  --pole-white: #FFFFFF;
  --pole-blue:  #1D4E9E;

  /* Rayons */
  --r-sm: 12px;  /* boutons, champ recherche, stepper */
  --r-md: 16px;  /* cartes, boutons nav, bloc total, Encaisser */
  --r-lg: 20px;  /* panneau ticket */

  /* Polices */
  --font-display: "Oswald", sans-serif;   /* titres, prix, total, Encaisser */
  --font-body:    "Inter", system-ui, sans-serif;
}
```

Polices Google Fonts : `Inter:wght@400;500;600;700` et `Oswald:wght@500;600`.

### Règles de couleur
- **Or uniquement sur fond noir/sombre** (ou en fond avec texte noir). Jamais de texte or sur blanc (contraste insuffisant).
- Prix des cartes : or. Prix des lignes du ticket : noir.
- Le bleu/blanc/rouge est **réservé au poteau** (logo + liseré du ticket). Ne pas l'utiliser ailleurs.
- Contraste visé : WCAG AA (4,5:1) pour tout le texte, y compris les petits libellés gris.

---

## 3. Typographie

| Usage | Police | Taille | Graisse | Autres |
|---|---|---|---|---|
| Titre catégorie (« SERVICES ») | Oswald | 32px | 600 | majuscules, letter-spacing 1.5px |
| Titre « TICKET » | Oswald | 24px | 600 | majuscules, letter-spacing 1px |
| Prix carte article | Oswald | 26px | 500 | couleur or, line-height 1 |
| Total TTC | Oswald | 40px | 600 | blanc sur noir |
| Prix ligne ticket | Oswald | 19px | 500 | |
| Bouton Encaisser | Oswald | 22px | 600 | majuscules, letter-spacing 2px |
| Logo « BARBER » | Oswald | 13px | — | letter-spacing 3px, or |
| Nom article (carte) | Inter | 16px | 600 | line-height 1.3 |
| Nom article (ticket) | Inter | 15px | 600 | |
| Méta (durée / stock / prix unitaire) | Inter | 13px | 400 | gris |
| Boutons secondaires | Inter | 14px | 600 | |
| Libellés nav latérale | Inter | 13px (Services/Produits), 11px (outils) | 600 / 500 | |

---

## 4. Mise en page

```
┌──────┬───────────────────────────────────────┬────────────────────┐
│ LOGO │ SERVICES              [🔍 Rechercher] │ ▓▓ liseré rayé ▓▓  │
│      │ 12 prestations                        │ TICKET  [Client][⏸]│
│[Serv]│ ┌──────┐┌──────┐┌──────┐┌──────┐      │────────────────────│
│[Prod]│ │carte ││carte ││carte ││carte │      │ ligne   [🗑 1 +] 38€│
│      │ └──────┘└──────┘└──────┘└──────┘      │ ligne   [🗑 1 +] 22€│
│      │ ┌──────┐┌──────┐┌──────┐┌──────┐      │                    │
│      │ │      ││      ││      ││      │      │────────────────────│
│ Plan.│ └──────┘└──────┘└──────┘└──────┘      │[Partiel][Bon cad.] │
│ Hist.│                                       │┌──── TOTAL ───────┐│
│ Rec. │                                       ││2 art. HT·TVA 60€ ││
│ Admin│                                       │└──────────────────┘│
│ Quit.│                                       │[   ENCAISSER  →   ]│
└──────┴───────────────────────────────────────┴────────────────────┘
  96px          flexible (min ~520px)              380–440px
```

- Conteneur racine : `display:flex; flex-wrap:wrap; height:100vh; min-height:720px; background:var(--bg-app)`.
- **Barre latérale** : `flex: 0 0 96px`, colonne, padding 18px 8px, gap 22px, bordure droite 1px `--border-side`. Outils poussés en bas (`margin-top:auto`).
- **Liste** : `flex: 999 1 520px; min-width:0`, padding 24px 24px 24px 28px, colonne avec gap 20px. La grille scrolle seule (`overflow-y:auto`), l'en-tête reste fixe.
- **Caisse** : `flex: 1 1 380px; max-width:440px`, padding 16px 16px 16px 0. Contient une carte blanche `border-radius:20px; overflow:hidden` en colonne. Seule la zone des lignes scrolle.
- Taille de référence : 1280×800. Doit fonctionner de 1024px à 1920px de large.

### Responsive (< 980px)
- Hauteur auto (plus de `100vh`), les colonnes s'empilent.
- La barre latérale devient une **barre horizontale** pleine largeur (nav et outils en ligne, scroll horizontal si besoin).
- Le ticket passe en pleine largeur sous la liste.

---

## 5. Composants

### 5.1 Logo poteau de barbier (haut de la barre latérale)
Empilement vertical centré, gap 8px :
1. Dôme or : 14×6px, `border-radius: 7px 7px 0 0`
2. Embout or : 30×7px, radius 3px
3. **Cylindre** 22×60px, radius 2px (voir CSS ci-dessous)
4. Embout or : 30×7px, radius 3px
5. Texte « BARBER » (Oswald 13px, or, letter-spacing 3px)

```css
.pole {
  background:
    linear-gradient(90deg, rgba(0,0,0,.35), rgba(255,255,255,.28) 38%,
                    rgba(255,255,255,0) 60%, rgba(0,0,0,.4)),            /* effet cylindre/verre */
    repeating-linear-gradient(-45deg,
      #C8102E 0 7px, #FFFFFF 7px 12px, #1D4E9E 12px 19px, #FFFFFF 19px 24px);
  background-position: 0 0, 0 0;
}
@keyframes poleturn {
  from { background-position: 0 0, 0 0; }
  to   { background-position: 0 0, 0 33.94px; } /* 24px × √2 = période verticale */
}
@media (prefers-reduced-motion: no-preference) {
  .pole-anim { animation: poleturn 2.6s linear infinite; }
}
```
L'animation (rayures qui « tournent ») doit pouvoir être désactivée par un réglage.

### 5.2 Boutons de catégorie (Services / Produits)
- `<button>` 78×84px, radius 16px, sans bordure, colonne centrée, gap 8px, icône 26px (trait 1.8) + libellé 13px/600.
- **Actif** : fond `--gold`, texte/icône `--text-on-gold`. `aria-pressed="true"`.
- **Inactif** : fond transparent, texte `--text-side-inactive`.
- Icônes : ciseaux (Services), flacon (Produits) — SVG en trait type Lucide.
- Changer de catégorie **vide la recherche**.

### 5.3 Outils de la barre latérale
- Planning, Historique, Recette (du jour), Admin, Quitter (déconnexion).
- `<button>` 78×56px, radius 12px, fond transparent, texte `--text-side`, icône 20px + libellé 11px/500. Survol : texte blanc.
- Icônes : calendrier, horloge-retour, graphique barres, engrenage, porte-sortie.

### 5.4 En-tête de liste
- Gauche : titre catégorie (H1) + sous-titre gris (« 12 prestations » / « 9 produits en vente »).
- Droite : champ recherche `type="search"`, largeur ~340px (min 220px), hauteur 48px, radius 12px, fond `--bg-card`, bordure `--border-dark`, texte blanc 15px, icône loupe à gauche (padding-left 44px). Placeholder : « Rechercher ou scanner… ». Label accessible masqué visuellement.
- Recherche : filtre en direct sur le nom (insensible à la casse) dans la catégorie active. Doit aussi accepter un code-barres (scanner = clavier).

### 5.5 Carte article
- Grille : `grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: 14px`.
- `<button>` : min-height 112px, padding 16px, radius 16px, fond `--bg-card`, bordure 1.5px `--border-dark`, colonne `justify-content: space-between`, texte aligné à gauche.
  - Haut : nom (16px/600, padding-right 30px pour le badge).
  - Bas (ligne) : à gauche méta grise 13px — **Services** : icône horloge + durée (« 30 min ») ; **Produits** : « Stock 14 » ; à droite prix Oswald 26px or (« 25 € », décimales seulement si nécessaires).
- **Déjà dans le ticket** : bordure en or + **badge quantité** en haut à droite (pastille or 26px, texte noir 13px/700).
- Survol : fond `--bg-card-hover`.
- Clic : ajoute 1 au ticket (ou incrémente la ligne existante).
- Aucun résultat : texte centré gris « Aucun article ne correspond à « … ». »

### 5.6 Panneau ticket
1. **Liseré rayé** 6px en haut (rayures -45° rouge 10px / blanc 6px / bleu 10px / blanc 6px).
2. **En-tête** (padding 18px 20px 14px) : « TICKET » à gauche ; à droite bouton « Client de passage » (icône personne, h 44px, bordure claire) + bouton icône **Mettre en attente** (pause, 44×44, `aria-label`).
3. Séparateur 1px `--divider-light`.
4. **Lignes** (zone scrollable, padding 6px 20px) — chaque ligne : padding 12px 0, bordure basse `--row-divider` :
   - Nom (15px/600) + dessous « 38,00 € × 1 » (13px, `--text-muted-light`).
   - **Stepper** bordé (radius 12px) : bouton − (40×44) | quantité (15px/700) | bouton + (40×44), fonds `--bg-ticket-btn`.
     Quand quantité = 1, le − devient une **corbeille** et retire la ligne (`aria-label="Retirer <nom>"`).
   - Total ligne à droite (Oswald 19px, min-width 76px, aligné à droite).
5. **Ticket vide** : icône ciseaux grise 40px, « Ticket vide » (16px/600) et « Touchez un service ou un produit pour l'ajouter. »
6. **Pied** (padding 16px 20px 20px, bordure haute, gap 12px) :
   - Grille 2 colonnes : **Paiement partiel** (icône carte) et **Bon cadeau** (icône cadeau) — h 48px, fond blanc, bordure claire.
   - **Bloc total** noir (radius 16px, padding 16px 18px) : à gauche « 2 articles » et « HT 50,00 € · TVA 10,00 € » (13px, `--text-side`) ; à droite total TTC Oswald 40px blanc.
   - **Bouton ENCAISSER** : pleine largeur, h 64px, radius 16px, fond or, texte noir Oswald 22px majuscules + flèche →.

---

## 6. Comportements

| Action | Effet |
|---|---|
| Clic Services / Produits | Change la liste, met à jour titre et compteur, vide la recherche |
| Saisie recherche / scan | Filtre la liste de la catégorie active |
| Clic carte | +1 dans le ticket ; badge et bordure or sur la carte |
| + sur une ligne | Quantité +1 |
| − sur une ligne | Quantité −1 ; à 1 → corbeille, retire la ligne |
| Client de passage | Ouvre le choix du client (fonction existante de l'appli coiffeur) |
| Pause | Met le ticket en attente (fonction existante) |
| Paiement partiel / Bon cadeau | Fonctions existantes de l'appli coiffeur |
| Encaisser | Ouvre l'écran de paiement existant |
| Planning / Historique / Recette / Admin / Quitter | Mêmes cibles que l'appli coiffeur (Planning et Admin peuvent ouvrir un nouvel onglet) |

Calculs : les prix sont **TTC** ; HT = TTC / (1 + taux) ; TVA = TTC − HT (taux par article à reprendre de la base, 20 % par défaut dans la maquette). Format monétaire français : `60,00 €`.

---

## 7. Accessibilité et tactile
- Uniquement de vrais `<button>`, `<input>` + `<label>` (pas de `div` cliquables).
- Cibles tactiles ≥ 44px.
- `aria-label` sur tous les boutons ne contenant qu'une icône.
- SVG décoratifs : `aria-hidden="true"`.
- Focus visible : `outline: 2px solid var(--gold); outline-offset: 2px`.
- Respecter `prefers-reduced-motion` pour l'animation du poteau.
- Pas d'emoji dans l'interface ; icônes SVG en trait (style Lucide, `stroke-width` 1.8–2).

---

## 8. Ce qui reste repris de l'appli coiffeur
- Logique métier, base de données, encaissement, clients, bons cadeaux, paiement partiel, historique, recette, planning, admin.
- **Supprimé** pour la version barber : onglets Forfaits / Cures, grille de familles de services, pagination des familles.
- Les données de la maquette (noms, prix, stocks) sont des **exemples** : utiliser le catalogue réel.
