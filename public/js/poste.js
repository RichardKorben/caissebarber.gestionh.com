/**
 * BarberPay — Historique et Recette : réimpression (duplicata), annulation, ouverture et clôture de la caisse.
 * Les textes venant des données sont insérés avec textContent.
 */
(function () {
  'use strict';

  var CSRF = document.querySelector('meta[name="csrf"]').content;
  var fenetre = document.getElementById('fenetre');
  var contenu = document.getElementById('fenetre-contenu');

  function el(tag, attributs, enfants) {
    var n = document.createElement(tag);
    Object.keys(attributs || {}).forEach(function (k) {
      var v = attributs[k];
      if (v === null || v === undefined || v === false) { return; }
      if (k === 'texte') { n.textContent = v; } else if (k === 'classe') { n.className = v; }
      else if (k.indexOf('on') === 0) { n.addEventListener(k.slice(2), v); } else { n.setAttribute(k, v === true ? '' : v); }
    });
    (enfants || []).forEach(function (e) { if (e) { n.appendChild(typeof e === 'string' ? document.createTextNode(e) : e); } });
    return n;
  }

  var minuteur = null;
  function toast(message, erreur) {
    var t = document.getElementById('toast');
    t.textContent = message;
    t.className = 'toast' + (erreur ? ' erreur' : '');
    t.hidden = false;
    clearTimeout(minuteur);
    minuteur = setTimeout(function () { t.hidden = true; }, 3500);
  }

  function api(action, corps) {
    return fetch('/api/caisse.php?action=' + encodeURIComponent(action), {
      method: 'POST', credentials: 'same-origin',
      headers: { 'X-CSRF-Token': CSRF, 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify(corps || {})
    }).then(function (r) {
      if (r.status === 401) { window.location.href = '/login.php'; throw new Error('Session expirée'); }
      if (r.status === 423) { window.location.href = '/'; throw new Error('Caisse verrouillée'); }
      return r.json().catch(function () { return { ok: false, erreur: 'Réponse illisible du serveur.' }; });
    }).then(function (j) { if (!j.ok) { throw new Error(j.erreur || 'Erreur.'); } return j; });
  }

  /** « 12,5 » → 1250 centimes ; null si invalide. */
  function centimes(saisie) {
    var t = String(saisie).replace(/[\s  €]/g, '').replace(',', '.');
    if (!/^\d{1,7}(\.\d{0,2})?$/.test(t)) { return null; }
    var p = t.split('.');
    return parseInt(p[0], 10) * 100 + parseInt(((p[1] || '') + '00').slice(0, 2), 10);
  }
  function format(c) {
    var n = c < 0; c = Math.abs(c);
    return (n ? '−' : '') + Math.floor(c / 100).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ') + ',' + String(c % 100).padStart(2, '0') + ' €';
  }

  function ouvrir(titre, elements) {
    contenu.textContent = '';
    contenu.appendChild(el('h2', { id: 'fenetre-titre', texte: titre }));
    elements.forEach(function (e) { if (e) { contenu.appendChild(e); } });
    if (!fenetre.open) { fenetre.showModal(); }
  }
  function fermer() { if (fenetre.open) { fenetre.close(); } }
  contenu.addEventListener('submit', function (e) { e.preventDefault(); });
  fenetre.addEventListener('click', function (e) { if (e.target === fenetre) { fermer(); } });
  function bouton(texte, classe, action) { return el('button', { type: 'button', classe: 'bouton' + (classe ? ' ' + classe : ''), texte: texte, onclick: action }); }

  function imprimer(url) {
    var ancien = document.getElementById('cadre-impression');
    if (ancien) { ancien.remove(); }
    document.body.appendChild(el('iframe', { id: 'cadre-impression', title: 'Impression', src: url, style: 'position:fixed;width:0;height:0;border:0;left:-9999px' }));
  }

  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-duplicata], [data-annuler], [data-imprimer-z]');
    if (!b) { return; }

    if (b.hasAttribute('data-duplicata')) {
      b.disabled = true;
      api('duplicata', { ticketId: parseInt(b.getAttribute('data-duplicata'), 10) }).then(function (r) {
        imprimer('/t/' + r.duplicata.jeton + '?imprimer=1&duplicata=' + r.duplicata.numero);
        toast('Duplicata n° ' + r.duplicata.numero + ' envoyé à l\'impression.');
      }, function (err) { toast(err.message, true); }).then(function () { b.disabled = false; });
    }

    if (b.hasAttribute('data-imprimer-z')) {
      imprimer('/z.php?id=' + encodeURIComponent(b.getAttribute('data-imprimer-z')) + '&imprimer=1');
    }

    if (b.hasAttribute('data-annuler')) {
      if (b.getAttribute('data-refus')) { toast(b.getAttribute('data-refus'), true); return; }
      var motif = '';
      var champ = el('input', { type: 'text', id: 'motif', maxlength: '120', placeholder: 'Motif de l\'annulation', autocomplete: 'off' });
      var erreur = el('p', { classe: 'erreur-champ', role: 'alert' });
      var choix = el('div', { classe: 'raccourcis' });
      ['Erreur de saisie', 'Client remboursé', 'Mauvais moyen de paiement'].forEach(function (m) {
        choix.appendChild(el('button', { type: 'button', classe: 'puce', texte: m, onclick: function () { champ.value = m; } }));
      });
      ouvrir('Annuler le ticket n° ' + b.getAttribute('data-numero'), [
        el('p', { texte: 'Un ticket d\'annulation de ' + b.getAttribute('data-montant') + ' (montant négatif) sera créé, remboursé par les mêmes moyens de paiement. Le ticket d\'origine est conservé.' }),
        el('label', { for: 'motif', texte: 'Motif' }), champ, choix, erreur,
        el('div', { classe: 'actions-formulaire' }, [
          bouton('Retour', 'bouton-discret', fermer),
          bouton('Annuler le ticket', 'bouton-danger', function () {
            motif = champ.value.trim();
            if (!motif) { erreur.textContent = 'Indiquez un motif.'; return; }
            this.disabled = true;
            var btn = this;
            api('annuler', { ticketId: parseInt(b.getAttribute('data-annuler'), 10), motif: motif }).then(function (r) {
              ouvrir('Ticket annulé', [
                el('p', { texte: 'Ticket d\'annulation n° ' + r.annulation.numeroAffiche + ' : ' + format(r.annulation.total) + '.' }),
                el('div', { classe: 'actions-formulaire' }, [
                  bouton('Imprimer', '', function () { imprimer('/t/' + r.annulation.jeton + '?imprimer=1'); }),
                  bouton('Terminé', 'bouton-principal', function () { window.location.reload(); })
                ])
              ]);
            }, function (err) { erreur.textContent = err.message; btn.disabled = false; });
          })
        ])
      ]);
    }
  });

  // ── Recette : ouverture ────────────────────────────────────────────
  var formOuvrir = document.querySelector('[data-ouvrir]');
  if (formOuvrir) {
    formOuvrir.addEventListener('submit', function (e) {
      e.preventDefault();
      var saisie = formOuvrir.querySelector('input').value.trim();
      var fond = saisie === '' ? 0 : centimes(saisie);
      if (fond === null) { toast('Montant du fond invalide.', true); return; }
      api('ouvrir', { fond: fond }).then(function () { window.location.reload(); }, function (err) { toast(err.message, true); });
    });
  }

  // ── Recette : clôture du jour (Z) ──────────────────────────────────
  var formZ = document.querySelector('[data-cloturer]');
  if (formZ) {
    var attendues = parseInt(formZ.getAttribute('data-attendues'), 10);
    var champZ = formZ.querySelector('input');
    var ecart = document.getElementById('ecart');
    champZ.addEventListener('input', function () {
      var c = centimes(champZ.value.trim());
      if (champZ.value.trim() === '' || c === null) { ecart.textContent = 'Attendu : ' + format(attendues); ecart.className = 'aide'; return; }
      var d = c - attendues;
      ecart.textContent = d === 0 ? 'Compte juste ✓' : 'Écart : ' + (d > 0 ? '+' : '') + format(d) + (d > 0 ? ' (en trop)' : ' (manquant)');
      ecart.className = 'aide ' + (d === 0 ? 'ecart-ok' : 'ecart-ko');
    });
    formZ.addEventListener('submit', function (e) {
      e.preventDefault();
      var saisie = champZ.value.trim();
      var comptees = saisie === '' ? null : centimes(saisie);
      if (saisie !== '' && comptees === null) { toast('Montant des espèces invalide.', true); return; }
      ouvrir('Clôturer la journée ?', [
        el('p', { texte: 'Net du jour : ' + formZ.getAttribute('data-net') + '.' + (comptees === null ? ' Espèces non comptées.' : ' Espèces comptées : ' + format(comptees) + ' (écart ' + format(comptees - attendues) + ').') }),
        el('p', { texte: 'Après la clôture, plus aucune vente ni annulation n\'est possible aujourd\'hui. Cette opération est définitive.' }),
        el('div', { classe: 'actions-formulaire' }, [
          bouton('Retour', 'bouton-discret', fermer),
          bouton('Clôturer', 'bouton-principal', function () {
            this.disabled = true;
            var btn = this;
            api('cloturer', { especesComptees: comptees }).then(function (r) {
              window.location.href = '/z.php?id=' + r.cloture.id;
            }, function (err) { toast(err.message, true); btn.disabled = false; fermer(); });
          })
        ])
      ]);
    });
  }
})();
