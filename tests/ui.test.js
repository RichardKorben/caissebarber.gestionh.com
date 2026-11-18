/**
 * Tests de l'écran de caisse dans un navigateur simulé (jsdom).
 * Usage : node tests/ui.test.js <page de caisse enregistrée.html> <racine du projet>
 * (tests/run.sh enregistre la page en se connectant au site de développement.)
 */
const { JSDOM } = require('jsdom');
const fs = require('fs');
const [page, R] = [process.argv[2], process.argv[3]];
let html = fs.readFileSync(page, 'utf8').replace(/<script src="[^"]+"><\/script>/g, '');
let echecs = 0, total = 0;
const ok = (cond, nom, detail) => { total++; if (cond) console.log('  OK   ' + nom); else { echecs++; console.log('  ÉCHEC ' + nom + (detail !== undefined ? ' → ' + detail : '')); } };

function demarrer(stockage, reponsesApi) {
  const dom = new JSDOM(html, { runScripts: 'outside-only', url: 'https://caissebarber.gestionh.com/', pretendToBeVisual: true });
  const w = dom.window;
  // jsdom n'implémente pas <dialog>.showModal ni fetch
  w.HTMLDialogElement.prototype.showModal = function () { this.open = true; };
  w.HTMLDialogElement.prototype.close = function () { this.open = false; };
  w.appelsApi = [];
  w.fetch = (url, opt) => { const action = new URL(url, 'https://caissebarber.gestionh.com/').searchParams.get('action'); w.appelsApi.push({ action, corps: opt.body ? JSON.parse(opt.body) : null });
    const r = (reponsesApi && reponsesApi[action]) || { status: 200, json: { ok: true, attente: 0 } };
    return Promise.resolve({ status: r.status, json: () => Promise.resolve(r.json) }); };
  if (stockage) { Object.entries(stockage).forEach(([k, v]) => w.localStorage.setItem(k, v)); }
  const donnees = html.match(/window\.BARBERPAY = (.*?);<\/script>/)[1];
  w.eval('window.BARBERPAY = ' + donnees);
  w.eval(fs.readFileSync(R + '/public/js/calcul.js', 'utf8'));
  w.eval(fs.readFileSync(R + '/public/js/caisse.js', 'utf8'));
  return w;
}
const $ = (w, s) => w.document.querySelector(s);
const $$ = (w, s) => Array.from(w.document.querySelectorAll(s));
const carte = (w, nom) => $$(w, '.carte-article').find(c => c.querySelector('.carte-nom').textContent === nom);
const txt = (w, s) => ($(w, s) || {}).textContent;
const nbsp = s => (s || '').replace(/[  ]/g, ' ');

let w = demarrer();
const D = w.BARBERPAY;
console.log('Démarrage');
ok(!$(w, '#verrou').hidden === false, 'caisse ouverte (gérant connecté, pas d\'écran de verrouillage)');
ok($$(w, '.carte-article').length === 6, '6 services affichés', $$(w, '.carte-article').length);
ok(txt(w, '#compteur-categorie') === '6 prestations', 'compteur « 6 prestations »', txt(w, '#compteur-categorie'));
ok(nbsp(txt(w, '.carte-article .carte-prix')) === '26 €', 'prix court « 26 € »', txt(w, '.carte-article .carte-prix'));
ok(!$(w, '#ticket-vide').hidden && $(w, '#bouton-encaisser').disabled, 'ticket vide affiché, Encaisser désactivé');
ok(!$(w, '#bloc-barbier').hidden, 'choix du barbier visible (plusieurs membres)');
ok($$(w, '#barbier option').length === D.membres.length, 'tous les membres actifs dans la liste (' + D.membres.length + ')');

console.log('Ajout d\'articles');
carte(w, 'Coupe homme').click(); carte(w, 'Coupe homme').click();
ok($$(w, '.ligne-ticket').length === 1, 'deux clics = une ligne');
ok(txt(w, '.stepper output') === '2', 'quantité 2');
ok(txt(w, '.carte-badge') === '2' && carte(w, 'Coupe homme').classList.contains('dans-ticket'), 'badge « 2 » et bordure or sur la vignette');
ok(nbsp(txt(w, '#total')) === '52,00 €', 'total 52,00 €', txt(w, '#total'));
ok(nbsp(txt(w, '#total-tva')) === 'HT 43,33 € · TVA 8,67 €', 'HT/TVA (salon assujetti)', txt(w, '#total-tva'));
ok(txt(w, '#total-articles') === '2 articles', '« 2 articles »');
$$(w, '[data-categorie="produit"]')[0].click();
ok($$(w, '.carte-article').length === 2 && txt(w, '#titre-categorie') === 'Produits', 'onglet Produits : 2 produits');
ok($$(w, '.carte-meta').some(m => /^(Stock \d+|Rupture)$/.test(m.textContent)), 'stock affiché sur la vignette');
carte(w, 'Cire mate').click();
ok(nbsp(txt(w, '#total')) === '66,90 €', 'total après Cire mate 66,90 €', txt(w, '#total'));

console.log('Quantités');
const lignes = () => $$(w, '.ligne-ticket');
const boutonsLigne = i => lignes()[i].querySelectorAll('.stepper button');
boutonsLigne(0)[0].click(); // − sur Coupe homme (2 → 1)
ok(lignes()[0].querySelector('.stepper output').textContent === '1', '− : quantité 2 → 1');
ok(boutonsLigne(0)[0].getAttribute('aria-label').startsWith('Retirer'), 'à 1, le − devient la corbeille');
boutonsLigne(0)[1].click();
ok(lignes()[0].querySelector('.stepper output').textContent === '2', '+ : quantité 1 → 2');

console.log('Remise sur le ticket');
$(w, '[data-action="remise-ticket"]').click();
ok($(w, '#fenetre').open && txt(w, '#fenetre-titre') === 'Remise sur le ticket', 'fenêtre de remise ouverte');
$$(w, '.raccourcis button').find(b => b.textContent === '10 %').click();
$$(w, '.fenetre-actions button').find(b => b.textContent === 'Appliquer').click();
ok(!$(w, '#fenetre').open, 'fenêtre fermée après Appliquer');
const attendu = w.Calcul.ticket([{ prix: 2600, quantite: 2, taux: 2000 }, { prix: 1490, quantite: 1, taux: 2000 }], { type: 'pct', valeur: 1000 });
ok(nbsp(txt(w, '#total')) === nbsp(w.Calcul.format(attendu.total)), 'total remisé = calcul (' + nbsp(w.Calcul.format(attendu.total)) + ')', txt(w, '#total'));
ok(nbsp(txt(w, '#total-remise')) === 'Remise −6,69 €', 'ligne « Remise −6,69 € »', txt(w, '#total-remise'));
ok($(w, '#bouton-remise').classList.contains('actif') && nbsp($(w, '#bouton-remise').textContent).includes('10 %'), 'bouton Remise indique 10 %');

console.log('Remise sur une ligne (Offert)');
lignes()[1].querySelector('.ligne-texte').click();
$$(w, '#fenetre button').find(b => b.textContent === 'Remise sur cette ligne').click();
$$(w, '.raccourcis button').find(b => b.textContent === 'Offert').click();
$$(w, '.fenetre-actions button').find(b => b.textContent === 'Appliquer').click();
ok(lignes()[1].textContent.includes('offert') && lignes()[1].querySelector('s'), 'ligne Cire mate offerte (prix barré)');

console.log('Remise en € trop élevée');
$(w, '[data-action="remise-ticket"]').click();
$$(w, '.segments button').find(b => b.textContent === 'En €').click();
const pave = t => $$(w, '#fenetre .pave button').find(b => b.textContent === t).click();
['9', '9', '9'].forEach(pave);
$$(w, '.fenetre-actions button').find(b => b.textContent === 'Appliquer').click();
ok($(w, '#fenetre').open && txt(w, '#fenetre .verrou-erreur').includes('dépasse'), 'remise de 999 € refusée', txt(w, '#fenetre .verrou-erreur'));
$$(w, '.fenetre-actions button').find(b => b.textContent === 'Retirer').click();
ok(!$(w, '#bouton-remise').classList.contains('actif'), 'remise du ticket retirée');

console.log('Montant libre');
$(w, '[data-action="montant-libre"]').click();
$(w, '#libre-libelle').value = 'Shampoing';
['1', '5', ',', '5'].forEach(pave);
ok(nbsp(txt(w, '#fenetre .affichage')) === '15,50 €', 'affichage 15,50 €', txt(w, '#fenetre .affichage'));
$$(w, '.fenetre-actions button').find(b => b.textContent === 'Ajouter').click();
ok(lignes().some(l => l.textContent.includes('Shampoing')), 'ligne « Shampoing » ajoutée');

console.log('Recherche et code-barres');
$$(w, '[data-categorie="service"]')[0].click();
const rech = $(w, '#recherche');
rech.value = 'degr'; rech.dispatchEvent(new w.Event('input'));
ok($$(w, '.carte-article').length === 1 && txt(w, '.carte-nom') === 'Dégradé', 'recherche « degr » (sans accent) → Dégradé');
rech.dispatchEvent(new w.KeyboardEvent('keydown', { key: 'Enter' }));
ok(lignes().some(l => l.textContent.includes('Dégradé')) && rech.value === '', 'Entrée ajoute le seul résultat et vide la recherche');
rech.value = 'zzz'; rech.dispatchEvent(new w.Event('input'));
ok(!$(w, '#aucun').hidden && txt(w, '#aucun').includes('zzz'), 'message « aucun article »');
rech.value = ''; rech.dispatchEvent(new w.Event('input'));
const avant = lignes().length;
'3760123456789'.split('').forEach(k => w.document.body.dispatchEvent(new w.KeyboardEvent('keydown', { key: k, bubbles: true })));
w.document.body.dispatchEvent(new w.KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
ok(lignes().length === avant + 1 && lignes().some(l => l.textContent.includes('Cire mate') && !l.textContent.includes('offert')), 'scan du code-barres de la Cire mate (nouvelle ligne car l\'autre est offerte)');

console.log('Plafond');
$(w, '[data-action="montant-libre"]').click();
['9','9','9','9','9'].forEach(pave);
$$(w, '.fenetre-actions button').find(b => b.textContent === 'Ajouter').click();
['9','9','9','9','9'].forEach(pave);
const nb = lignes().length;
$(w, '[data-action="montant-libre"]').click(); ['9','9','9','9','9'].forEach(pave);
$$(w, '.fenetre-actions button').find(b => b.textContent === 'Ajouter').click();
ok(lignes().length === nb && !$(w, '#toast').hidden && txt(w, '#toast').includes('trop élevé'), 'au-delà de 99 999,99 € : refusé avec message', txt(w, '#toast'));
$(w, '#fenetre').open = false;

console.log('Persistance');
const sauve = w.localStorage.getItem('barberpay:caissebarber:ticket');
const nbLignes = lignes().length; const totalAvant = txt(w, '#total');
const w2 = demarrer({ 'barberpay:caissebarber:ticket': sauve });
ok($$(w2, '.ligne-ticket').length === nbLignes && txt(w2, '#total') === totalAvant, 'rechargement de la page : ticket retrouvé');
const w3 = demarrer({ 'barberpay:caissebarber:ticket': '{pas du json' });
ok($$(w3, '.ligne-ticket').length === 0, 'ticket local corrompu : repart à vide sans planter');

console.log('Mise en attente');
w2.appelsApi.length = 0;
$(w2, '#bouton-attente').click();
setTimeout(() => {
  const appel = w2.appelsApi.find(a => a.action === 'attente_mettre');
  ok(appel && appel.corps.ticket.lignes.length === nbLignes && !('cle' in appel.corps.ticket.lignes[0]), 'pause : ticket envoyé au serveur (sans clés internes)');
  ok($$(w2, '.ligne-ticket').length === 0, 'pause : ticket vidé');

  console.log('Verrouillage');
  const w4 = demarrer(null, { attente_liste: { status: 423, json: { ok: false, erreur: 'Caisse verrouillée.' } } });
  $(w4, '#bouton-attente').click();
  setTimeout(() => {
    ok(!$(w4, '#verrou').hidden, 'réponse 423 → écran de verrouillage');
    const boutonsMembres = $$(w4, '#verrou-membres button');
    const sansPin = D.membres.filter(m => !m.aPin).map(m => m.prenom);
    ok(boutonsMembres.length === D.membres.length && boutonsMembres.every(b => b.disabled === sansPin.some(p => b.textContent.startsWith(p))), 'membres listés, ceux sans PIN grisés');
    boutonsMembres.find(b => b.textContent.startsWith('Yanis')).click();
    ok(!$(w4, '#verrou-pin').hidden && txt(w4, '#verrou-consigne') === 'Code de Yanis', 'pavé PIN de Yanis');
    ['4', '8', '2', '1'].forEach(t => $$(w4, '#verrou-pave button').find(b => b.textContent === t).click());
    ok(txt(w4, '#verrou-points') === '●●●●', '4 points affichés, chiffres masqués');
    encaissements();
  }, 50);
}, 50);

// ── Encaissement ────────────────────────────────────────────────────────
function encaissements() {
  const attendre = (ms) => new Promise(r => setTimeout(r, ms || 30));
  const vente = (rendu) => ({ status: 200, json: { ok: true, vente: { id: 7, numero: 7, numeroAffiche: '000007', jeton: 'a'.repeat(32), total: 0, rendu: rendu || 0, deja: false, url: 'https://caissebarber.gestionh.com/t/' + 'a'.repeat(32) } } });
  const clic = (w, texte, sel) => { const b = $$(w, (sel || '#fenetre button')).find(x => x.textContent.trim() === texte); if (!b) { throw new Error('bouton « ' + texte + ' » introuvable'); } b.click(); };
  const paveF = (w, t) => clic(w, t, '#fenetre .pave button');
  const EFFACER = '⌫';
  (async () => {
    console.log('Encaissement par carte');
    let w = demarrer(null, { encaisser: vente(0) });
    carte(w, 'Coupe homme').click();
    $(w, '#bouton-encaisser').click();
    ok($(w, '#fenetre').open && $$(w, '.bouton-moyen').length === 4, 'fenêtre d\'encaissement : 4 moyens de paiement');
    clic(w, 'Carte bancaire', '.bouton-moyen');
    ok(txt(w, '#fenetre').includes('terminal de paiement'), 'carte : rappel d\'encaisser sur le terminal');
    clic(w, 'Paiement accepté');
    await attendre();
    const appel = w.appelsApi.find(a => a.action === 'encaisser');
    ok(appel && appel.corps.paiements.length === 1 && appel.corps.paiements[0].moyen === 'cb' && appel.corps.paiements[0].montant === 2600, 'envoi : CB 26,00 €');
    ok(appel && appel.corps.totalAffiche === 2600 && /^[0-9a-f-]{36}$/.test(appel.corps.cle), 'envoi : total affiché et clé anti-doublon');
    ok(txt(w, '#fenetre-titre').startsWith('Encaissé') && txt(w, '#fenetre').includes('000007'), 'écran final avec le numéro du ticket');
    ok(txt(w, '#fenetre').includes('/t/' + 'a'.repeat(32)), 'lien du ticket affiché (QR code ou lien)');
    ok($$(w, '.ligne-ticket').length === 0 && !$(w, '#ticket-vide').hidden, 'ticket vidé après l\'encaissement');

    console.log('Espèces avec monnaie');
    w = demarrer(null, { encaisser: vente(4000) });
    carte(w, 'Coupe + barbe').click();
    $$(w, '[data-categorie="produit"]')[0].click();
    const stockAvant = w.BARBERPAY.articles.find(a => a.nom === 'Huile à barbe').stock;
    carte(w, 'Huile à barbe').click();
    $(w, '#bouton-encaisser').click();
    clic(w, 'Espèces', '.bouton-moyen');
    ok($$(w, '#fenetre .raccourcis button').some(b => nbsp(b.textContent) === 'Appoint 60 €'), 'raccourci « Appoint 60 € »');
    ok($(w, '.fenetre-actions .principal').disabled, 'Valider désactivé tant que rien n\'est reçu');
    paveF(w, '1'); paveF(w, '0'); paveF(w, '0');
    ok(nbsp(txt(w, '.encaisse-rendu')) === 'À rendre : 40,00 €', '100 € reçus → « À rendre : 40,00 € »', txt(w, '.encaisse-rendu'));
    clic(w, 'Valider');
    await attendre();
    const e = w.appelsApi.find(a => a.action === 'encaisser').corps.paiements[0];
    ok(e.moyen === 'especes' && e.montant === 6000 && e.recu === 10000, 'envoi : espèces 60 €, reçu 100 €');
    ok(nbsp(txt(w, '.encaisse-rendu.grand')) === 'À rendre : 40,00 €', 'écran final : monnaie à rendre en grand');
    ok(w.BARBERPAY.articles.find(a => a.nom === 'Huile à barbe').stock === stockAvant - 1, 'stock affiché diminué de 1');

    console.log('Paiement en plusieurs fois');
    w = demarrer(null, { encaisser: vente(0) });
    carte(w, 'Coupe + barbe').click();
    $(w, '[data-action="paiement-partiel"]').click();
    ok(txt(w, '#fenetre-titre') === 'Paiement en plusieurs fois', 'fenêtre de paiement en plusieurs fois');
    clic(w, 'Carte bancaire', '.bouton-moyen');
    ok(nbsp(txt(w, '#fenetre .affichage')) === '38,00 €', 'montant proposé = reste à payer');
    [EFFACER, EFFACER, EFFACER, EFFACER, EFFACER, '2', '0'].forEach(t => paveF(w, t));
    clic(w, 'Ajouter');
    ok(nbsp(txt(w, '.encaisse-reste')) === 'Reste à payer : 18,00 €', 'reste 18,00 € après 20 € en carte', txt(w, '.encaisse-reste'));
    clic(w, 'Espèces', '.bouton-moyen');
    paveF(w, '2'); paveF(w, '0');
    clic(w, 'Ajouter');
    ok(txt(w, '.encaisse-reste') === 'Tout est réglé.', '« Tout est réglé. »');
    clic(w, 'Valider l\'encaissement');
    await attendre();
    const ps = w.appelsApi.find(a => a.action === 'encaisser').corps.paiements;
    ok(ps.length === 2 && ps[0].montant === 2000 && ps[1].montant === 1800 && ps[1].recu === 2000, 'envoi : CB 20 € + espèces 18 € (20 € reçus)');

    console.log('Prix modifiés entre-temps');
    w = demarrer(null, { encaisser: { status: 409, json: { ok: false, erreur: 'Les prix ont changé depuis l\'ouverture de la caisse. Rechargez la page et vérifiez le ticket.' } } });
    carte(w, 'Dégradé').click();
    $(w, '#bouton-encaisser').click();
    clic(w, 'Chèque', '.bouton-moyen');
    clic(w, 'Valider');
    await attendre();
    ok(txt(w, '#fenetre-titre') === 'Encaissement impossible' && $$(w, '#fenetre button').some(b => b.textContent === 'Recharger la page'), 'message + bouton « Recharger la page »');
    ok($$(w, '.ligne-ticket').length === 1, 'le ticket est conservé');

    console.log('\n' + (total - echecs) + '/' + total + ' tests réussis' + (echecs ? ' — ' + echecs + ' ÉCHEC(S)' : ''));
    process.exit(echecs ? 1 : 0);
  })().catch(err => { console.log('  ÉCHEC exception : ' + err.message); process.exit(1); });
}
