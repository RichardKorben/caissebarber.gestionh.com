/**
 * BarberPay — écran de caisse.
 *
 * Le ticket en cours vit dans le navigateur (et dans localStorage pour survivre à un rechargement).
 * Il n'a aucune valeur fiscale tant qu'il n'est pas encaissé : le serveur refera tous les calculs
 * à l'encaissement (étape 3). Les noms d'articles sont insérés avec textContent, jamais en HTML.
 */
(function () {
  'use strict';

  var D = window.BARBERPAY;
  var C = window.Calcul;
  var CSRF = document.querySelector('meta[name="csrf"]').content;
  var CLE_STOCKAGE = 'barberpay:' + D.salon + ':ticket';
  var TAUX_DEFAUT = 2000;
  var MAX_MONTANT = 9999999;

  var etat = {
    categorie: 'service',
    recherche: '',
    operateur: D.operateur,
    attente: D.attente,
    ticket: { barbierId: null, remise: null, lignes: [] }
  };
  var compteurCle = 0;

  // ── Outils ───────────────────────────────────────────────────────────
  var $ = function (id) { return document.getElementById(id); };

  /** Crée un élément sans jamais interpréter de HTML venant des données. */
  function el(tag, attributs, enfants) {
    var n = document.createElement(tag);
    Object.keys(attributs || {}).forEach(function (k) {
      var v = attributs[k];
      if (v === null || v === undefined || v === false) { return; }
      if (k === 'texte') { n.textContent = v; }
      else if (k === 'classe') { n.className = v; }
      else if (k.indexOf('on') === 0) { n.addEventListener(k.slice(2), v); }
      else { n.setAttribute(k, v === true ? '' : v); }
    });
    (enfants || []).forEach(function (e) { if (e) { n.appendChild(typeof e === 'string' ? document.createTextNode(e) : e); } });
    return n;
  }

  function sansAccents(texte) {
    return texte.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
  }

  /** « 12,5 » → 1250 (centièmes), null si invalide. Sert pour les euros (centimes) et les % (centièmes de %). */
  function centiemes(saisie) {
    var t = saisie.replace(',', '.');
    if (!/^\d{1,7}(\.\d{0,2})?$/.test(t)) { return null; }
    var morceaux = t.split('.');
    return parseInt(morceaux[0], 10) * 100 + parseInt(((morceaux[1] || '') + '00').slice(0, 2), 10);
  }

  function membre(id) {
    return D.membres.filter(function (m) { return m.id === id; })[0] || null;
  }

  var minuteurToast = null;
  function toast(message, erreur) {
    var t = $('toast');
    t.textContent = message;
    t.className = 'toast' + (erreur ? ' erreur' : '');
    t.hidden = false;
    clearTimeout(minuteurToast);
    minuteurToast = setTimeout(function () { t.hidden = true; }, 3200);
  }

  function api(action, corps) {
    var options = { method: corps === undefined ? 'GET' : 'POST', headers: { 'X-CSRF-Token': CSRF, 'Accept': 'application/json' }, credentials: 'same-origin' };
    if (corps !== undefined) {
      options.headers['Content-Type'] = 'application/json';
      options.body = JSON.stringify(corps);
    }
    return fetch('/api/caisse.php?action=' + encodeURIComponent(action), options).then(function (reponse) {
      if (reponse.status === 401) { window.location.href = '/login.php'; throw new Error('Session expirée'); }
      return reponse.json().catch(function () { return { ok: false, erreur: 'Réponse illisible du serveur.' }; }).then(function (json) {
        if (reponse.status === 423) { afficherVerrou(); }
        if (!json.ok) { throw new Error(json.erreur || 'Erreur.'); }
        return json;
      });
    });
  }

  // ── Ticket : stockage local ──────────────────────────────────────────
  function sauver() {
    try { localStorage.setItem(CLE_STOCKAGE, JSON.stringify(etat.ticket)); } catch (e) { /* stockage indisponible : tant pis */ }
  }

  function charger() {
    try {
      var t = JSON.parse(localStorage.getItem(CLE_STOCKAGE) || 'null');
      if (t && Array.isArray(t.lignes)) { adopterTicket(t); calculer(); }
    } catch (e) { etat.ticket = { barbierId: null, remise: null, lignes: [] }; /* ticket local illisible : on repart d'un ticket vide */ }
  }

  /** Remplace le ticket en cours (rechargement ou reprise d'un ticket en attente). */
  function adopterTicket(t) {
    etat.ticket = {
      barbierId: t.barbierId || null,
      remise: t.remise || null,
      lignes: (t.lignes || []).map(function (l) {
        return { cle: ++compteurCle, articleId: l.articleId || null, type: l.type, nom: l.nom, prix: l.prix, taux: l.taux, quantite: l.quantite, barbierId: l.barbierId || null, remise: l.remise || null };
      })
    };
  }

  /** Version envoyée au serveur (sans les clés internes). */
  function ticketPourServeur() {
    return {
      barbierId: etat.ticket.barbierId,
      remise: etat.ticket.remise,
      lignes: etat.ticket.lignes.map(function (l) {
        return { articleId: l.articleId, type: l.type, nom: l.nom, prix: l.prix, taux: l.taux, quantite: l.quantite, barbierId: l.barbierId, remise: l.remise };
      })
    };
  }

  function vider() {
    etat.ticket = { barbierId: barbierParDefaut(), remise: null, lignes: [] };
  }

  function barbierParDefaut() {
    if (etat.operateur && membre(etat.operateur.id)) { return etat.operateur.id; }
    return D.membres.length ? D.membres[0].id : null;
  }

  function calculer() {
    return C.ticket(etat.ticket.lignes, etat.ticket.remise);
  }

  /** Applique une modification du ticket ; l'annule si le ticket dépasse le plafond. */
  function essayer(modification) {
    var avant = JSON.stringify(etat.ticket);
    modification();
    try {
      calculer();
    } catch (e) {
      etat.ticket = JSON.parse(avant);
      changer(); // réaffiche avec les lignes restaurées (les boutons pointent sur les bons objets)
      toast(e.message, true);
      return false;
    }
    changer();
    return true;
  }

  function changer() {
    sauver();
    afficherTicket();
    afficherGrille();
    majBadgeAttente();
  }

  // ── Ajout d'articles ─────────────────────────────────────────────────
  function ajouterArticle(article) {
    var faire = function () { essayer(function () {
      var barbier = etat.ticket.barbierId;
      var existante = etat.ticket.lignes.filter(function (l) {
        return l.articleId === article.id && l.barbierId === barbier && !l.remise;
      })[0];
      if (existante) {
        if (existante.quantite < 999) { existante.quantite++; }
      } else {
        etat.ticket.lignes.push({ cle: ++compteurCle, articleId: article.id, type: article.type, nom: article.nom, prix: article.prix, taux: article.taux, quantite: 1, barbierId: barbier, remise: null });
      }
    }); };
    if (article.type === 'produit' && article.stock !== null && article.stock <= 0) {
      confirmer('Stock épuisé', '« ' + article.nom + ' » est à 0 en stock d\'après le catalogue. L\'ajouter quand même ?', 'Ajouter', faire);
    } else {
      faire();
    }
  }

  function articleParCode(code) {
    return D.articles.filter(function (a) { return a.codeBarre && a.codeBarre === code; })[0] || null;
  }

  // ── Grille des articles ──────────────────────────────────────────────
  function quantitesDansTicket() {
    var q = {};
    etat.ticket.lignes.forEach(function (l) { if (l.articleId) { q[l.articleId] = (q[l.articleId] || 0) + l.quantite; } });
    return q;
  }

  function articlesVisibles() {
    var r = sansAccents(etat.recherche.trim());
    return D.articles.filter(function (a) {
      return a.type === etat.categorie && (r === '' || sansAccents(a.nom).indexOf(r) !== -1 || (a.codeBarre && a.codeBarre === etat.recherche.trim()));
    });
  }

  function afficherGrille() {
    var grille = $('grille');
    var quantites = quantitesDansTicket();
    var liste = articlesVisibles();
    var total = D.articles.filter(function (a) { return a.type === etat.categorie; }).length;
    var service = etat.categorie === 'service';
    $('titre-categorie').textContent = service ? 'Services' : 'Produits';
    $('compteur-categorie').textContent = service
      ? total + ' prestation' + (total > 1 ? 's' : '')
      : total + ' produit' + (total > 1 ? 's' : '') + ' en vente';

    grille.textContent = '';
    liste.forEach(function (a) {
      var q = quantites[a.id] || 0;
      var meta;
      if (service) {
        meta = a.duree ? el('span', { classe: 'carte-meta' }, [horloge(), a.duree + ' min']) : el('span', { classe: 'carte-meta' });
      } else {
        meta = el('span', { classe: 'carte-meta' + (a.stock !== null && a.stock <= 0 ? ' stock-vide' : ''), texte: a.stock === null ? '' : (a.stock <= 0 ? 'Rupture' : 'Stock ' + a.stock) });
      }
      grille.appendChild(el('button', {
        type: 'button',
        classe: 'carte-article' + (q ? ' dans-ticket' : ''),
        'aria-label': a.nom + ', ' + C.formatCourt(a.prix) + (q ? ', ' + q + ' dans le ticket' : ''),
        onclick: function () { ajouterArticle(a); }
      }, [
        el('span', { classe: 'carte-nom', texte: a.nom }),
        el('span', { classe: 'carte-bas' }, [meta, el('span', { classe: 'carte-prix', texte: C.formatCourt(a.prix) })]),
        q ? el('span', { classe: 'carte-badge', 'aria-hidden': 'true', texte: String(q) }) : null
      ]));
    });

    var aucun = $('aucun');
    if (liste.length === 0) {
      aucun.hidden = false;
      aucun.textContent = etat.recherche.trim()
        ? 'Aucun article ne correspond à « ' + etat.recherche.trim() + ' ».'
        : (service ? 'Aucun service. Ajoutez-en dans Admin → Catalogue.' : 'Aucun produit. Ajoutez-en dans Admin → Catalogue.');
    } else {
      aucun.hidden = true;
    }
  }

  function horloge() {
    var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('width', '14'); svg.setAttribute('height', '14'); svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('fill', 'none'); svg.setAttribute('stroke', 'currentColor'); svg.setAttribute('stroke-width', '1.8');
    svg.setAttribute('aria-hidden', 'true');
    var c = document.createElementNS('http://www.w3.org/2000/svg', 'circle'); c.setAttribute('cx', '12'); c.setAttribute('cy', '12'); c.setAttribute('r', '10');
    var p = document.createElementNS('http://www.w3.org/2000/svg', 'path'); p.setAttribute('d', 'M12 6v6l4 2');
    svg.appendChild(c); svg.appendChild(p);
    return svg;
  }

  function iconeSvg(d, taille) {
    var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('width', String(taille || 18)); svg.setAttribute('height', String(taille || 18)); svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('fill', 'none'); svg.setAttribute('stroke', 'currentColor'); svg.setAttribute('stroke-width', '2');
    svg.setAttribute('stroke-linecap', 'round'); svg.setAttribute('stroke-linejoin', 'round'); svg.setAttribute('aria-hidden', 'true');
    d.split('|').forEach(function (trace) {
      var p = document.createElementNS('http://www.w3.org/2000/svg', 'path'); p.setAttribute('d', trace); svg.appendChild(p);
    });
    return svg;
  }
  var ICONE_CORBEILLE = 'M3 6h18|M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6|M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2';
  var ICONE_MOINS = 'M5 12h14';
  var ICONE_PLUS = 'M12 5v14|M5 12h14';

  // ── Ticket ───────────────────────────────────────────────────────────
  function afficherTicket() {
    var calcul = calculer();
    var conteneur = $('lignes');
    var vide = etat.ticket.lignes.length === 0;
    conteneur.textContent = '';
    conteneur.hidden = vide;
    $('ticket-vide').hidden = !vide;
    var plusieursBarbiers = D.membres.length > 1;

    etat.ticket.lignes.forEach(function (l, i) {
      var r = calcul.lignes[i];
      var detail = [C.format(l.prix) + ' × ' + l.quantite];
      if (plusieursBarbiers && l.barbierId !== etat.ticket.barbierId && membre(l.barbierId)) { detail.push(membre(l.barbierId).prenom); }
      var elDetail = el('span', { classe: 'ligne-detail', texte: detail.join(' · ') });
      if (l.remise) {
        elDetail.appendChild(document.createTextNode(' · '));
        elDetail.appendChild(el('span', { classe: 'remise-ligne', texte: 'remise ' + (l.remise.type === 'pct' ? (l.remise.valeur === 10000 ? 'offert' : C.formatTaux(l.remise.valeur)) : C.format(l.remise.valeur)) }));
      }
      var moins = l.quantite === 1
        ? el('button', { type: 'button', 'aria-label': 'Retirer ' + l.nom, onclick: function () { retirerLigne(l.cle); } }, [iconeSvg(ICONE_CORBEILLE)])
        : el('button', { type: 'button', 'aria-label': 'Diminuer la quantité de ' + l.nom, onclick: function () { l.quantite--; changer(); } }, [iconeSvg(ICONE_MOINS)]);
      conteneur.appendChild(el('div', { classe: 'ligne-ticket' }, [
        el('button', { type: 'button', classe: 'ligne-texte', 'aria-label': 'Options de ' + l.nom, onclick: function () { optionsLigne(l); } }, [
          el('span', { classe: 'ligne-nom', texte: l.nom }), elDetail
        ]),
        el('div', { classe: 'stepper' }, [
          moins,
          el('output', { texte: String(l.quantite), 'aria-label': 'Quantité' }),
          el('button', { type: 'button', 'aria-label': 'Augmenter la quantité de ' + l.nom, onclick: function () { if (l.quantite < 999) { essayer(function () { trouverLigne(l.cle).quantite++; }); } } }, [iconeSvg(ICONE_PLUS)])
        ]),
        el('div', { classe: 'ligne-total' }, [
          r.remise ? el('s', { texte: C.format(r.brut) }) : null,
          C.format(r.net)
        ])
      ]));
    });

    // Bloc total
    var nb = calcul.nb_articles;
    $('total-articles').textContent = nb + ' article' + (nb > 1 ? 's' : '');
    var remises = calcul.remise_ticket + calcul.lignes.reduce(function (s, r) { return s + r.remise; }, 0);
    var elRemise = $('total-remise');
    elRemise.hidden = remises === 0;
    elRemise.textContent = 'Remise −' + C.format(remises);
    $('total-tva').textContent = D.franchise
      ? 'TVA non applicable, art. 293 B du CGI'
      : 'HT ' + C.format(calcul.total_ht) + ' · TVA ' + C.format(calcul.total_tva);
    var total = $('total');
    total.textContent = C.format(calcul.total);
    total.classList.toggle('long', calcul.total >= 100000);
    $('bouton-encaisser').disabled = vide || D.journeeCloturee;
    $('bouton-remise').classList.toggle('actif', !!etat.ticket.remise);
    $('bouton-remise').lastChild.textContent = etat.ticket.remise ? 'Remise ' + libelleRemise(etat.ticket.remise) : 'Remise';
    afficherBarbier();
  }

  function libelleRemise(r) {
    return r.type === 'pct' ? (r.valeur === 10000 ? 'offert' : C.formatTaux(r.valeur)) : C.format(r.valeur);
  }

  function trouverLigne(cle) {
    return etat.ticket.lignes.filter(function (x) { return x.cle === cle; })[0];
  }

  function retirerLigne(cle) {
    etat.ticket.lignes = etat.ticket.lignes.filter(function (l) { return l.cle !== cle; });
    if (etat.ticket.lignes.length === 0) { etat.ticket.remise = null; }
    changer();
  }

  // ── Choix du barbier (seulement s'il y a plusieurs membres actifs) ───
  function preparerBarbier() {
    var bloc = $('bloc-barbier');
    var select = $('barbier');
    bloc.hidden = D.membres.length <= 1;
    select.textContent = '';
    D.membres.forEach(function (m) { select.appendChild(el('option', { value: String(m.id), texte: m.prenom })); });
    select.addEventListener('change', function () {
      var ancien = etat.ticket.barbierId;
      var nouveau = parseInt(select.value, 10);
      // Les lignes qui suivaient le barbier du ticket le suivent encore ; celles changées à la main restent.
      etat.ticket.lignes.forEach(function (l) { if (l.barbierId === ancien) { l.barbierId = nouveau; } });
      etat.ticket.barbierId = nouveau;
      changer();
    });
  }

  function afficherBarbier() {
    if (D.membres.length <= 1) { return; }
    if (!membre(etat.ticket.barbierId)) { etat.ticket.barbierId = barbierParDefaut(); }
    var m = membre(etat.ticket.barbierId);
    $('barbier').value = m ? String(m.id) : '';
    $('pastille-barbier').style.background = m ? m.couleur : 'transparent';
  }

  // ── Fenêtres ─────────────────────────────────────────────────────────
  var fenetre = $('fenetre');
  function ouvrir(titre, contenu) {
    var f = $('fenetre-contenu');
    f.textContent = '';
    f.appendChild(el('h2', { id: 'fenetre-titre', texte: titre }));
    contenu.forEach(function (c) { if (c) { f.appendChild(c); } });
    if (!fenetre.open) { fenetre.showModal(); }
  }
  function fermer() { if (fenetre.open) { fenetre.close(); } }
  fenetre.addEventListener('click', function (e) { if (e.target === fenetre) { fermer(); } }); // clic à côté
  // Entrée dans un champ ne doit pas fermer la fenêtre (formulaire method="dialog").
  $('fenetre-contenu').addEventListener('submit', function (e) { e.preventDefault(); });

  function actions(boutons) {
    return el('div', { classe: 'fenetre-actions' }, boutons);
  }
  function bouton(texte, classe, action) {
    return el('button', { type: 'button', classe: 'bouton-f' + (classe ? ' ' + classe : ''), texte: texte, onclick: action });
  }

  function confirmer(titre, message, libelleOk, action) {
    ouvrir(titre, [el('p', { texte: message }), actions([bouton('Annuler', '', fermer), bouton(libelleOk, 'principal', function () { fermer(); action(); })])]);
  }

  /** Pavé numérique tactile. saisie() renvoie la chaîne tapée ; changement() est appelé à chaque touche. */
  function pave(changement, maxLongueur) {
    var valeur = '';
    var conteneur = el('div', { classe: 'pave' });
    ['1', '2', '3', '4', '5', '6', '7', '8', '9', ',', '0', '⌫'].forEach(function (t) {
      conteneur.appendChild(el('button', {
        type: 'button',
        classe: t === '⌫' ? 'touche-texte' : null,
        'aria-label': t === '⌫' ? 'Effacer' : (t === ',' ? 'Virgule' : null),
        texte: t,
        onclick: function () {
          if (t === '⌫') { valeur = valeur.slice(0, -1); }
          else if (t === ',') { if (valeur.indexOf(',') === -1) { valeur = (valeur || '0') + ','; } }
          else if (valeur.length < (maxLongueur || 9)) {
            var apresVirgule = valeur.indexOf(',') === -1 ? -1 : valeur.length - valeur.indexOf(',') - 1;
            if (apresVirgule < 2) { valeur = (valeur === '0' ? '' : valeur) + t; }
          }
          changement(valeur);
        }
      }));
    });
    return { element: conteneur, definir: function (v) { valeur = v; changement(valeur); } };
  }

  // ── Remise (sur le ticket ou sur une ligne) ──────────────────────────
  function fenetreRemise(cible) {
    var surLigne = !!cible;
    var base = surLigne ? cible.prix * cible.quantite : calculer().sous_total;
    var actuelle = surLigne ? cible.remise : etat.ticket.remise;
    var type = actuelle ? actuelle.type : 'pct';
    var saisie = actuelle ? String(actuelle.valeur / 100).replace('.', ',') : '';
    var motif = actuelle ? actuelle.motif : '';
    var affichage = el('output', { classe: 'affichage', 'aria-live': 'polite' });
    var erreur = el('p', { classe: 'verrou-erreur', role: 'alert' });
    var segPct = el('button', { type: 'button', texte: 'En %', onclick: function () { type = 'pct'; p.definir(''); majSegments(); } });
    var segEur = el('button', { type: 'button', texte: 'En €', onclick: function () { type = 'eur'; p.definir(''); majSegments(); } });
    var raccourcis = el('div', { classe: 'raccourcis' });
    [['10 %', '10'], ['20 %', '20'], ['50 %', '50'], ['Offert', '100']].forEach(function (r) {
      raccourcis.appendChild(el('button', { type: 'button', texte: r[0], onclick: function () { type = 'pct'; majSegments(); p.definir(r[1]); } }));
    });
    var motifs = el('div', { classe: 'raccourcis', role: 'group', 'aria-label': 'Motif (facultatif)' });
    ['Fidélité', 'Geste commercial', 'Erreur de prix'].forEach(function (m) {
      var b = el('button', { type: 'button', texte: m, 'aria-pressed': String(motif === m), onclick: function () {
        motif = motif === m ? '' : m;
        Array.prototype.forEach.call(motifs.children, function (x) { x.setAttribute('aria-pressed', String(x.textContent === motif)); });
      } });
      motifs.appendChild(b);
    });
    function majSegments() {
      segPct.setAttribute('aria-pressed', String(type === 'pct'));
      segEur.setAttribute('aria-pressed', String(type === 'eur'));
      raccourcis.hidden = type !== 'pct';
    }
    function valeurRemise() { return centiemes(saisie || '0'); }
    function majAffichage(v) {
      saisie = v;
      var c = centiemes(v || '0');
      affichage.textContent = type === 'pct' ? (v || '0') + ' %' : C.format(c === null ? 0 : c);
      erreur.textContent = '';
      if (c !== null && c > 0) {
        var r = C.remise(base, { type: type, valeur: Math.min(c, type === 'pct' ? 10000 : c) });
        if (type === 'pct' && c > 10000) { erreur.textContent = '100 % au maximum.'; }
        else { erreur.textContent = 'Soit −' + C.format(r) + ' sur ' + C.format(base) + '.'; erreur.style.color = 'var(--text-side)'; return; }
      }
      erreur.style.color = '';
    }
    var p = pave(majAffichage, 9);
    majSegments();
    p.definir(saisie);

    ouvrir(surLigne ? 'Remise : ' + cible.nom : 'Remise sur le ticket', [
      el('div', { classe: 'segments', role: 'group', 'aria-label': 'Type de remise' }, [segPct, segEur]),
      affichage, erreur, raccourcis, p.element,
      el('p', { texte: 'Motif (facultatif)' }), motifs,
      actions([
        actuelle ? bouton('Retirer', 'danger', function () { appliquer(null); }) : bouton('Annuler', '', fermer),
        bouton('Appliquer', 'principal', function () {
          var v = valeurRemise();
          if (!v) { erreur.style.color = ''; erreur.textContent = 'Saisissez une remise.'; return; }
          if (type === 'pct' && v > 10000) { erreur.style.color = ''; erreur.textContent = '100 % au maximum.'; return; }
          if (type === 'eur' && v > base) { erreur.style.color = ''; erreur.textContent = 'La remise dépasse le montant (' + C.format(base) + ').'; return; }
          appliquer({ type: type, valeur: v, motif: motif });
        })
      ])
    ]);

    function appliquer(remise) {
      if (surLigne) { cible.remise = remise; } else { etat.ticket.remise = remise; }
      fermer();
      changer();
    }
  }

  // ── Options d'une ligne ──────────────────────────────────────────────
  function optionsLigne(l) {
    var contenu = [el('p', { texte: C.format(l.prix) + ' × ' + l.quantite })];
    if (D.membres.length > 1) {
      var choix = el('div', { classe: 'choix-membres', role: 'group', 'aria-label': 'Barbier pour cette ligne' });
      D.membres.forEach(function (m) {
        choix.appendChild(el('button', { type: 'button', 'aria-pressed': String(l.barbierId === m.id), onclick: function () { l.barbierId = m.id; fermer(); changer(); } }, [
          el('span', { classe: 'pastille', style: 'background:' + m.couleur, 'aria-hidden': 'true' }), m.prenom
        ]));
      });
      contenu.push(el('p', { texte: 'Barbier' }), choix);
    }
    contenu.push(el('button', { type: 'button', classe: 'bouton-f', texte: l.remise ? 'Modifier la remise (' + libelleRemise(l.remise) + ')' : 'Remise sur cette ligne', onclick: function () { fenetreRemise(l); } }));
    contenu.push(actions([bouton('Retirer la ligne', 'danger', function () { fermer(); retirerLigne(l.cle); }), bouton('Fermer', '', fermer)]));
    ouvrir(l.nom, contenu);
  }

  // ── Montant libre ────────────────────────────────────────────────────
  function fenetreMontantLibre() {
    var type = 'service';
    var montant = null;
    var libelle = el('input', { type: 'text', id: 'libre-libelle', maxlength: '80', placeholder: 'Prestation', autocomplete: 'off' });
    var affichage = el('output', { classe: 'affichage', 'aria-live': 'polite', texte: C.format(0) });
    var erreur = el('p', { classe: 'verrou-erreur', role: 'alert' });
    var segS = el('button', { type: 'button', texte: 'Service', onclick: function () { type = 'service'; maj(); } });
    var segP = el('button', { type: 'button', texte: 'Produit', onclick: function () { type = 'produit'; maj(); } });
    function maj() {
      segS.setAttribute('aria-pressed', String(type === 'service'));
      segP.setAttribute('aria-pressed', String(type === 'produit'));
      libelle.placeholder = type === 'service' ? 'Prestation' : 'Article';
    }
    var p = pave(function (v) { montant = centiemes(v || '0'); affichage.textContent = C.format(montant || 0); erreur.textContent = ''; }, 8);
    maj();
    ouvrir('Montant libre', [
      el('div', { classe: 'segments', role: 'group', 'aria-label': 'Type' }, [segS, segP]),
      el('div', {}, [el('label', { for: 'libre-libelle', texte: 'Libellé' }), libelle]),
      affichage, erreur, p.element,
      actions([bouton('Annuler', '', fermer), bouton('Ajouter', 'principal', function () {
        if (!montant || montant > MAX_MONTANT) { erreur.textContent = 'Saisissez un montant.'; return; }
        var nom = libelle.value.trim() || libelle.placeholder;
        if (essayer(function () {
          etat.ticket.lignes.push({ cle: ++compteurCle, articleId: null, type: type, nom: nom.slice(0, 80), prix: montant, taux: TAUX_DEFAUT, quantite: 1, barbierId: etat.ticket.barbierId, remise: null });
        })) { fermer(); }
      })])
    ]);
  }

  // ── Tickets en attente ───────────────────────────────────────────────
  function majBadgeAttente() {
    var b = $('badge-attente');
    b.hidden = !etat.attente;
    b.textContent = String(etat.attente);
    $('bouton-attente').setAttribute('aria-label', etat.ticket.lignes.length
      ? 'Mettre le ticket en attente'
      : 'Tickets en attente (' + etat.attente + ')');
  }

  function mettreEnAttente() {
    return api('attente_mettre', { ticket: ticketPourServeur() }).then(function (r) {
      etat.attente = r.attente;
      vider();
      changer();
      majBadgeAttente();
    });
  }

  function clicAttente() {
    if (etat.ticket.lignes.length) {
      mettreEnAttente().then(function () { toast('Ticket mis en attente.'); }, function (e) { toast(e.message, true); });
    } else {
      listeAttente();
    }
  }

  function listeAttente() {
    api('attente_liste').then(function (r) {
      etat.attente = r.tickets.length;
      majBadgeAttente();
      if (!r.tickets.length) { toast('Aucun ticket en attente.'); fermer(); return; }
      var liste = el('ul', { classe: 'liste-attente' });
      r.tickets.forEach(function (t) {
        var heure = (t.cree_le || '').slice(11, 16);
        liste.appendChild(el('li', {}, [
          el('div', { classe: 'infos' }, [el('strong', { texte: t.libelle }), el('span', { texte: t.nb_articles + ' article' + (t.nb_articles > 1 ? 's' : '') + ' · ' + heure })]),
          el('span', { classe: 'montant', texte: C.format(t.total_ttc) }),
          el('button', { type: 'button', classe: 'bouton-f principal', texte: 'Reprendre', onclick: function () { reprendre(t.id); } }),
          el('button', { type: 'button', classe: 'bouton-f danger', 'aria-label': 'Supprimer ' + t.libelle, texte: '✕', onclick: function () {
            confirmer('Supprimer ce ticket ?', '« ' + t.libelle + ' » (' + C.format(t.total_ttc) + ') sera supprimé. Ce n\'était pas une vente : rien n\'est perdu côté comptabilité.', 'Supprimer', function () {
              api('attente_supprimer', { id: t.id }).then(function (x) { etat.attente = x.attente; majBadgeAttente(); toast('Ticket supprimé.'); }, function (e) { toast(e.message, true); });
            });
          } })
        ]));
      });
      ouvrir('Tickets en attente', [liste, actions([bouton('Fermer', '', fermer)])]);
    }, function (e) { toast(e.message, true); });
  }

  function reprendre(id) {
    var avant = etat.ticket.lignes.length ? mettreEnAttente() : Promise.resolve();
    avant.then(function () { return api('attente_reprendre', { id: id }); }).then(function (r) {
      adopterTicket(r.ticket);
      etat.attente = r.attente;
      fermer();
      changer();
      majBadgeAttente();
      toast('Ticket repris.');
    }, function (e) { toast(e.message, true); listeAttente(); });
  }

  // ── Encaissement ─────────────────────────────────────────────────────
  var MOYENS = [
    { code: 'cb', nom: 'Carte bancaire' },
    { code: 'especes', nom: 'Espèces' },
    { code: 'cheque', nom: 'Chèque' },
    { code: 'autre', nom: 'Autre' }
  ];
  function nomMoyen(code) { return MOYENS.filter(function (m) { return m.code === code; })[0].nom; }

  function uuid() {
    if (window.crypto && crypto.randomUUID) { return crypto.randomUUID(); }
    var o = new Uint8Array(16); crypto.getRandomValues(o);
    o[6] = (o[6] & 15) | 64; o[8] = (o[8] & 63) | 128;
    var h = Array.prototype.map.call(o, function (b) { return (b + 256).toString(16).slice(1); }).join('');
    return h.slice(0, 8) + '-' + h.slice(8, 12) + '-' + h.slice(12, 16) + '-' + h.slice(16, 20) + '-' + h.slice(20);
  }

  /** Montants proposés pour les espèces : l'appoint et les billets au-dessus. */
  function suggestionsEspeces(total) {
    var s = [total];
    [500, 1000, 2000, 5000, 10000].forEach(function (pas) {
      var v = Math.ceil(total / pas) * pas;
      if (v > total && s.indexOf(v) === -1) { s.push(v); }
    });
    return s.slice(0, 4);
  }

  function encaisser(partiel) {
    var total = calculer().total;
    var paiements = [];
    var cle = uuid(); // la même clé si on réessaie : jamais de double encaissement
    var envoi = false;

    function reste() { return total - paiements.reduce(function (s, p) { return s + p.montant; }, 0); }

    function vueChoix() {
      var r = reste();
      var contenu = [el('p', { classe: 'encaisse-total', texte: C.format(total) })];
      if (partiel || paiements.length) {
        var liste = el('ul', { classe: 'liste-attente' });
        paiements.forEach(function (p, i) {
          liste.appendChild(el('li', {}, [
            el('div', { classe: 'infos' }, [el('strong', { texte: nomMoyen(p.moyen) }), p.rendu ? el('span', { texte: 'reçu ' + C.format(p.recu) + ' · rendu ' + C.format(p.rendu) }) : null]),
            el('span', { classe: 'montant', texte: C.format(p.montant) }),
            el('button', { type: 'button', classe: 'bouton-f danger', 'aria-label': 'Retirer le paiement ' + nomMoyen(p.moyen), texte: '✕', onclick: function () { paiements.splice(i, 1); vueChoix(); } })
          ]));
        });
        if (paiements.length) { contenu.push(liste); }
        contenu.push(el('p', { classe: 'encaisse-reste', texte: r > 0 ? 'Reste à payer : ' + C.format(r) : 'Tout est réglé.' }));
      }
      if (total === 0) {
        contenu.push(el('p', { texte: 'Ticket entièrement offert : aucun paiement à encaisser.' }));
        contenu.push(actions([bouton('Annuler', '', fermer), bouton('Valider le ticket', 'principal', valider)]));
      } else if (r > 0) {
        var grille = el('div', { classe: 'moyens' });
        MOYENS.forEach(function (m) {
          grille.appendChild(el('button', { type: 'button', classe: 'bouton-moyen', texte: m.nom, onclick: function () {
            if (m.code === 'especes') { vueEspeces(); }
            else if (partiel) { vueMontant(m.code); }
            else { paiements = [{ moyen: m.code, montant: total }]; confirmerSans(m.code); }
          } }));
        });
        contenu.push(grille, actions([bouton('Annuler', '', fermer)]));
      } else {
        contenu.push(actions([bouton('Annuler', '', fermer), bouton('Valider l\'encaissement', 'principal', valider)]));
      }
      ouvrir(partiel ? 'Paiement en plusieurs fois' : 'Encaissement', contenu);
    }

    /** Carte, chèque, autre (paiement complet) : le terminal de paiement n'est pas relié, on confirme. */
    function confirmerSans(moyen) {
      ouvrir('Encaissement', [
        el('p', { classe: 'encaisse-total', texte: C.format(total) }),
        el('p', { texte: moyen === 'cb' ? 'Encaissez ' + C.format(total) + ' sur le terminal de paiement, puis validez une fois le paiement accepté.' : 'Paiement par ' + nomMoyen(moyen).toLowerCase() + ' de ' + C.format(total) + '.' }),
        actions([bouton('Retour', '', function () { paiements = []; vueChoix(); }), bouton(moyen === 'cb' ? 'Paiement accepté' : 'Valider', 'principal', valider)])
      ]);
    }

    function vueEspeces() {
      var du = reste();
      var recu = null;
      var affichage = el('output', { classe: 'affichage', 'aria-live': 'polite' });
      var rendu = el('p', { classe: 'encaisse-rendu', 'aria-live': 'polite' });
      var ok = bouton(partiel ? 'Ajouter' : 'Valider', 'principal', function () {
        if (recu === null || recu <= 0) { return; }
        var montant = Math.min(recu, du);
        paiements.push({ moyen: 'especes', montant: montant, recu: recu, rendu: recu - montant });
        if (partiel) { vueChoix(); } else { valider(); } // en plusieurs fois : récapitulatif avant de valider
      });
      function maj(v) {
        recu = centiemes(v || '0');
        affichage.textContent = C.format(recu || 0);
        var manque = !recu || (!partiel && recu < du);
        ok.disabled = manque;
        if (!recu) { rendu.textContent = 'Somme reçue du client'; }
        else if (recu >= du) { rendu.textContent = recu > du ? 'À rendre : ' + C.format(recu - du) : 'Compte juste'; }
        else { rendu.textContent = partiel ? 'Reste ' + C.format(du - recu) + ' à payer autrement' : 'Il manque ' + C.format(du - recu); }
      }
      var p = pave(maj, 8);
      var raccourcis = el('div', { classe: 'raccourcis' });
      suggestionsEspeces(du).forEach(function (v, i) {
        raccourcis.appendChild(el('button', { type: 'button', texte: i === 0 ? 'Appoint ' + C.formatCourt(v) : C.formatCourt(v), onclick: function () { p.definir(String(v / 100).replace('.', ',')); } }));
      });
      ouvrir('Espèces — ' + C.format(du), [affichage, rendu, raccourcis, p.element, actions([bouton('Retour', '', vueChoix), ok])]);
      maj('');
    }

    function vueMontant(moyen) {
      var du = reste();
      var montant = du;
      var affichage = el('output', { classe: 'affichage', 'aria-live': 'polite' });
      var erreur = el('p', { classe: 'verrou-erreur', role: 'alert' });
      var p = pave(function (v) { montant = centiemes(v || '0'); affichage.textContent = C.format(montant || 0); erreur.textContent = ''; }, 8);
      ouvrir(nomMoyen(moyen) + ' — montant', [
        el('p', { texte: 'Reste à payer : ' + C.format(du) }), affichage, erreur, p.element,
        actions([bouton('Retour', '', vueChoix), bouton('Ajouter', 'principal', function () {
          if (!montant) { erreur.textContent = 'Saisissez un montant.'; return; }
          if (montant > du) { erreur.textContent = 'Plus que le reste à payer (' + C.format(du) + ').'; return; }
          paiements.push({ moyen: moyen, montant: montant });
          vueChoix();
        })])
      ]);
      p.definir(String(du / 100).replace('.', ','));
    }

    function valider() {
      if (envoi) { return; }
      envoi = true;
      ouvrir('Encaissement', [el('p', { classe: 'encaisse-total', texte: C.format(total) }), el('p', { texte: 'Enregistrement du ticket…' })]);
      var vendus = etat.ticket.lignes.filter(function (l) { return l.type === 'produit' && l.articleId; })
        .map(function (l) { return { id: l.articleId, quantite: l.quantite }; });
      api('encaisser', {
        ticket: ticketPourServeur(),
        paiements: paiements.map(function (p) { var x = { moyen: p.moyen, montant: p.montant }; if (p.recu !== undefined) { x.recu = p.recu; } return x; }),
        totalAffiche: total,
        cle: cle
      }).then(function (r) {
        // Le ticket est enregistré : on vide la caisse et on met le stock affiché à jour.
        vendus.forEach(function (v) { D.articles.forEach(function (a) { if (a.id === v.id && a.stock !== null) { a.stock -= v.quantite; } }); });
        vider();
        changer();
        succes(r.vente);
      }, function (e) {
        envoi = false;
        var contenu = [el('p', { classe: 'verrou-erreur', role: 'alert', texte: e.message })];
        if (/rechargez/i.test(e.message)) {
          contenu.push(actions([bouton('Fermer', '', fermer), bouton('Recharger la page', 'principal', function () { window.location.reload(); })]));
        } else {
          contenu.push(el('p', { texte: 'Le ticket n\'a pas été enregistré. Vous pouvez réessayer sans risque de double encaissement.' }));
          contenu.push(actions([bouton('Fermer', '', fermer), bouton('Réessayer', 'principal', valider)]));
        }
        ouvrir('Encaissement impossible', contenu);
      });
    }

    var imprime = false;
    function succes(v) {
      var qr = el('div', { classe: 'qr', 'aria-hidden': 'true' });
      if (window.qrcode) {
        var q = window.qrcode(0, 'M'); q.addData(v.url); q.make();
        qr.innerHTML = q.createSvgTag({ cellSize: 5, margin: 2, scalable: true }); // SVG produit par la bibliothèque, sans donnée saisie
      } else {
        qr.appendChild(el('p', { classe: 'qr-lien', texte: v.url }));
      }
      var contenu = [
        el('p', { classe: 'encaisse-numero', texte: 'Ticket n° ' + v.numeroAffiche + (v.deja ? ' (déjà enregistré)' : '') }),
        v.rendu ? el('p', { classe: 'encaisse-rendu grand', texte: 'À rendre : ' + C.format(v.rendu) }) : null,
        qr,
        el('p', { classe: 'qr-texte', texte: window.qrcode ? 'Le client scanne ce code pour récupérer son ticket.' : 'Lien du ticket en ligne, à transmettre au client.' }),
        actions([
          bouton('Imprimer', '', function () {
            // 1re impression : l'original ; les suivantes sont des duplicatas numérotés (règle anti-fraude).
            if (!imprime) { imprime = true; imprimer(v.jeton); return; }
            api('duplicata', { ticketId: v.id }).then(function (r) {
              imprimer(v.jeton, r.duplicata.numero);
              toast('Duplicata n° ' + r.duplicata.numero + ' envoyé à l\'impression.');
            }, function (e) { toast(e.message, true); });
          }),
          bouton('Nouveau ticket', 'principal', fermer)
        ])
      ];
      ouvrir('Encaissé ✓', contenu);
    }

    vueChoix();
  }

  /** Impression du ticket (format 80 mm) sans quitter la caisse. */
  function imprimer(jeton, duplicata) {
    var ancien = document.getElementById('cadre-impression');
    if (ancien) { ancien.remove(); }
    var cadre = el('iframe', { id: 'cadre-impression', title: 'Impression du ticket', src: '/t/' + jeton + '?imprimer=1' + (duplicata ? '&duplicata=' + duplicata : ''), style: 'position:fixed;width:0;height:0;border:0;left:-9999px' });
    document.body.appendChild(cadre);
  }

  /** Met à jour les lignes d'un ticket gardé en mémoire avec le catalogue actuel (prix, nom, TVA). */
  function rafraichirPrix() {
    etat.ticket.lignes.forEach(function (l) {
      if (!l.articleId) { return; }
      var a = D.articles.filter(function (x) { return x.id === l.articleId; })[0];
      if (a) { l.nom = a.nom; l.prix = a.prix; l.taux = a.taux; l.type = a.type; }
    });
  }

  // ── Verrouillage ─────────────────────────────────────────────────────
  var dernierPing = 0;
  var derniereActivite = Date.now();

  function afficherVerrou() {
    etat.operateur = null;
    fermer();
    var conteneur = $('verrou-membres');
    conteneur.textContent = '';
    D.membres.forEach(function (m) {
      conteneur.appendChild(el('button', { type: 'button', disabled: !m.aPin, onclick: function () { demanderPin(m); } }, [
        el('span', { classe: 'pastille', style: 'background:' + m.couleur, 'aria-hidden': 'true' }),
        m.prenom,
        m.aPin ? null : el('small', { texte: 'pas de code PIN' })
      ]));
    });
    $('verrou-membres').hidden = false;
    $('verrou-pin').hidden = true;
    $('verrou-consigne').textContent = 'Qui est à la caisse ?';
    $('verrou').hidden = false;
    $('app').setAttribute('inert', '');
  }

  function demanderPin(m) {
    var pin = '';
    var points = $('verrou-points');
    var erreur = $('verrou-erreur');
    var paveEl = $('verrou-pave');
    $('verrou-membres').hidden = true;
    $('verrou-pin').hidden = false;
    $('verrou-consigne').textContent = 'Code de ' + m.prenom;
    erreur.textContent = '';
    function maj() { points.textContent = pin.replace(/./g, '●') || ' '; points.setAttribute('aria-label', pin.length + ' chiffre(s) saisi(s)'); }
    function valider() {
      if (pin.length < 4) { erreur.textContent = '4 à 6 chiffres.'; return; }
      var essai = pin;
      pin = ''; maj();
      api('deverrouiller', { membreId: m.id, pin: essai }).then(function (r) {
        etat.operateur = { id: r.operateur.id, prenom: r.operateur.prenom, role: r.operateur.role };
        $('verrou').hidden = true;
        $('app').removeAttribute('inert');
        derniereActivite = Date.now();
        if (!etat.ticket.lignes.length) { etat.ticket.barbierId = barbierParDefaut(); changer(); }
        toast('Bonjour ' + r.operateur.prenom + ' !');
      }, function (e) { erreur.textContent = e.message; });
    }
    paveEl.textContent = '';
    ['1', '2', '3', '4', '5', '6', '7', '8', '9', '⌫', '0', 'OK'].forEach(function (t) {
      paveEl.appendChild(el('button', {
        type: 'button', texte: t, classe: t.length > 1 || t === '⌫' ? 'touche-texte' : null,
        'aria-label': t === '⌫' ? 'Effacer' : (t === 'OK' ? 'Valider le code' : null),
        onclick: function () {
          erreur.textContent = '';
          if (t === '⌫') { pin = pin.slice(0, -1); } else if (t === 'OK') { valider(); return; } else if (pin.length < 6) { pin += t; }
          maj();
        }
      }));
    });
    maj();
  }

  function verrouiller() {
    api('verrouiller', {}).then(afficherVerrou, afficherVerrou);
  }

  /** Activité : repousse le verrouillage automatique (et prévient le serveur au plus une fois par minute). */
  function activite() {
    derniereActivite = Date.now();
    if (D.verrouillage && etat.operateur && Date.now() - dernierPing > 60000) {
      dernierPing = Date.now();
      api('activite', {}).catch(function () { /* 423 → écran de verrouillage déjà affiché */ });
    }
  }

  // ── Lecteur de code-barres (se comporte comme un clavier très rapide) ─
  var tampon = '';
  var dernierCaractere = 0;
  document.addEventListener('keydown', function (e) {
    var cible = e.target;
    var dansChamp = cible && (cible.tagName === 'INPUT' || cible.tagName === 'SELECT' || cible.tagName === 'TEXTAREA');
    if (dansChamp || fenetre.open || !$('verrou').hidden) { return; }
    var maintenant = Date.now();
    if (maintenant - dernierCaractere > 80) { tampon = ''; }
    dernierCaractere = maintenant;
    if (e.key === 'Enter') {
      if (tampon.length >= 4) {
        var a = articleParCode(tampon);
        if (a) { ajouterArticle(a); } else { toast('Code-barres inconnu : ' + tampon, true); }
        e.preventDefault();
      }
      tampon = '';
    } else if (e.key.length === 1) {
      tampon += e.key;
    }
  });

  // ── Démarrage ────────────────────────────────────────────────────────
  function demarrer() {
    charger();
    rafraichirPrix();
    if (!etat.ticket.barbierId) { etat.ticket.barbierId = barbierParDefaut(); }
    preparerBarbier();

    document.querySelectorAll('[data-categorie]').forEach(function (b) {
      b.addEventListener('click', function () {
        etat.categorie = b.getAttribute('data-categorie');
        etat.recherche = '';
        $('recherche').value = '';
        document.querySelectorAll('[data-categorie]').forEach(function (x) { x.setAttribute('aria-pressed', String(x === b)); });
        afficherGrille();
        $('grille').scrollTop = 0;
      });
    });

    var recherche = $('recherche');
    recherche.addEventListener('input', function () { etat.recherche = recherche.value; afficherGrille(); });
    recherche.addEventListener('keydown', function (e) {
      if (e.key !== 'Enter') { return; }
      e.preventDefault();
      var saisie = recherche.value.trim();
      var parCode = saisie ? articleParCode(saisie) : null;
      var visibles = articlesVisibles();
      var article = parCode || (visibles.length === 1 ? visibles[0] : null);
      if (article) {
        ajouterArticle(article);
        recherche.value = '';
        etat.recherche = '';
        afficherGrille();
      } else if (/^\d{8,}$/.test(saisie)) {
        toast('Code-barres inconnu : ' + saisie, true);
      }
    });

    document.addEventListener('click', function (e) {
      var cible = e.target.closest('[data-action]');
      if (!cible) { return; }
      switch (cible.getAttribute('data-action')) {
        case 'montant-libre': fenetreMontantLibre(); break;
        case 'remise-ticket':
          if (!etat.ticket.lignes.length) { toast('Ajoutez d\'abord un article.'); break; }
          fenetreRemise(null);
          break;
        case 'paiement-partiel':
        case 'encaisser':
          if (!etat.ticket.lignes.length) { toast('Le ticket est vide.'); break; }
          encaisser(cible.getAttribute('data-action') === 'paiement-partiel');
          break;
        case 'verrouiller': verrouiller(); break;
        case 'bientot':
          toast(cible.getAttribute('data-nom') + ' arrive à l\'étape ' + cible.getAttribute('data-etape') + '.');
          break;
      }
    });

    $('bouton-attente').addEventListener('click', clicAttente);

    $('lien-admin').addEventListener('click', function (e) {
      if (D.verrouillage && (!etat.operateur || etat.operateur.role !== 'gerant')) {
        e.preventDefault();
        toast('L\'administration est réservée au gérant : déverrouillez avec son code.', true);
      }
    });

    $('verrou-retour').addEventListener('click', afficherVerrou);
    $('bouton-verrouiller').hidden = !D.verrouillage;

    ['pointerdown', 'keydown'].forEach(function (type) { document.addEventListener(type, activite, { passive: true }); });
    if (D.verrouillage && D.verrouillageMinutes > 0) {
      setInterval(function () {
        if (etat.operateur && $('verrou').hidden && Date.now() - derniereActivite > D.verrouillageMinutes * 60000) { verrouiller(); }
      }, 15000);
    }

    afficherGrille();
    afficherTicket();
    majBadgeAttente();
    if (D.journeeCloturee) {
      $('bouton-encaisser').setAttribute('title', 'Journée clôturée');
      toast('La journée est clôturée (Z fait) : les encaissements reprendront demain.');
    }
    if (D.verrouillage && !etat.operateur) { afficherVerrou(); }
  }

  demarrer();
})();
