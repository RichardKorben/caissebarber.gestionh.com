/**
 * BarberPay — calcul d'un ticket en centimes entiers.
 * MÊME ALGORITHME que app/src/Calcul.php (le serveur refait le calcul à l'encaissement) : toute modification
 * doit être faite des deux côtés ; les tests comparent les deux versions sur les mêmes cas.
 *
 * Remise en % : valeur en centièmes de % (1000 = 10 %). Tous les nombres restent des entiers
 * (largement sous 2^53), donc aucun problème d'arrondi des nombres à virgule.
 */
(function (racine) {
  'use strict';

  var POURCENT = 'pct';
  var EUROS = 'eur';
  /** Plafond d'un ticket avant remises (99 999,99 €), comme Calcul::TOTAL_MAX : calculs exacts sous 2^53. */
  var TOTAL_MAX = 9999999;

  function remise(base, r) {
    if (!r || base <= 0) { return 0; }
    if (r.type === POURCENT) { return Math.floor((base * r.valeur + 5000) / 10000); }
    if (r.type === EUROS) { return Math.min(base, r.valeur); }
    throw new Error('Type de remise inconnu');
  }

  function htDepuisTtc(ttc, taux) {
    var diviseur = 10000 + taux;
    return Math.floor((2 * ttc * 10000 + diviseur) / (2 * diviseur));
  }

  /**
   * @param {Array<{prix:number, quantite:number, taux:number, remise?:{type:string, valeur:number}|null}>} lignes
   * @param {{type:string, valeur:number}|null} remiseTicket
   */
  function ticket(lignes, remiseTicket) {
    var resultats = [];
    var sousTotal = 0;
    var nbArticles = 0;
    var totalBrut = 0;
    lignes.forEach(function (l) {
      var brut = l.prix * l.quantite;
      totalBrut += brut;
      if (totalBrut > TOTAL_MAX) { throw new RangeError('Ticket trop élevé (99 999,99 € au maximum).'); }
      var r = remise(brut, l.remise || null);
      var net = brut - r;
      resultats.push({ brut: brut, remise: r, net: net, part_remise_ticket: 0, final: net });
      sousTotal += net;
      nbArticles += l.quantite;
    });

    var remiseGlobale = remise(sousTotal, remiseTicket || null);
    if (remiseGlobale > 0 && sousTotal > 0) {
      var parts = [];
      var restes = [];
      var distribue = 0;
      resultats.forEach(function (r, i) {
        var numerateur = remiseGlobale * r.net;
        parts[i] = Math.floor(numerateur / sousTotal);
        restes[i] = numerateur % sousTotal;
        distribue += parts[i];
      });
      var ordre = resultats.map(function (_, i) { return i; });
      ordre.sort(function (a, b) { return (restes[b] - restes[a]) || (a - b); });
      for (var k = 0, manque = remiseGlobale - distribue; k < manque; k++) { parts[ordre[k]]++; }
      resultats.forEach(function (r, i) { r.part_remise_ticket = parts[i]; r.final = r.net - parts[i]; });
    }

    var parTaux = {};
    lignes.forEach(function (l, i) { parTaux[l.taux] = (parTaux[l.taux] || 0) + resultats[i].final; });
    var tauxTries = Object.keys(parTaux).map(Number).sort(function (a, b) { return b - a; });
    var tva = [];
    var totalHt = 0;
    var totalTva = 0;
    tauxTries.forEach(function (taux) {
      var ttc = parTaux[taux];
      var ht = htDepuisTtc(ttc, taux);
      tva.push({ taux: taux, ttc: ttc, ht: ht, tva: ttc - ht });
      totalHt += ht;
      totalTva += ttc - ht;
    });

    return {
      lignes: resultats,
      sous_total: sousTotal,
      remise_ticket: remiseGlobale,
      total: sousTotal - remiseGlobale,
      nb_articles: nbArticles,
      tva: tva,
      total_ht: totalHt,
      total_tva: totalTva
    };
  }

  /** 2550 → « 25,50 € » */
  function format(centimes) {
    var negatif = centimes < 0;
    var c = Math.abs(centimes);
    var euros = Math.floor(c / 100).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
    return (negatif ? '−' : '') + euros + ',' + String(c % 100).padStart(2, '0') + ' €';
  }

  /** Prix des vignettes : 2500 → « 25 € », 2550 → « 25,50 € » */
  function formatCourt(centimes) {
    return centimes % 100 === 0
      ? Math.floor(centimes / 100).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ') + ' €'
      : format(centimes);
  }

  /** 2000 → « 20 % », 550 → « 5,5 % » */
  function formatTaux(centiemes) {
    var texte = (centiemes / 100).toString().replace('.', ',');
    return texte + ' %';
  }

  var api = { POURCENT: POURCENT, EUROS: EUROS, TOTAL_MAX: TOTAL_MAX, ticket: ticket, remise: remise, htDepuisTtc: htDepuisTtc, format: format, formatCourt: formatCourt, formatTaux: formatTaux };
  if (typeof module !== 'undefined' && module.exports) { module.exports = api; } else { racine.Calcul = api; }
})(this);
