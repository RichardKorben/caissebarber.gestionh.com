/**
 * Génère des tickets (cas choisis + 3 000 aléatoires, graine fixe), les calcule avec public/js/calcul.js
 * et écrit cas + résultats dans un fichier JSON. tests/calcul.php refait le calcul en PHP et compare.
 * Usage : node tests/calcul.js <fichier de sortie.json>
 */
const C = require('../public/js/calcul.js');
let graine = 12345;
const hasard = (n) => { graine = (graine * 1103515245 + 12345) % 2147483648; return graine % n; };
const taux = [2000, 1000, 550, 210, 0];
const remiseAuHasard = (base) => {
  const k = hasard(5);
  if (k === 0) { return { type: 'pct', valeur: 1 + hasard(10000) }; }
  if (k === 1) { return { type: 'eur', valeur: 1 + hasard(base + 500) }; }
  return null;
};
const cas = [
  { lignes: [{ prix: 2500, quantite: 1, taux: 2000 }], remise: null },
  { lignes: [{ prix: 3800, quantite: 1, taux: 2000 }, { prix: 2200, quantite: 1, taux: 2000 }], remise: null },
  { lignes: [1, 2, 3].map(() => ({ prix: 1000, quantite: 1, taux: 2000 })), remise: { type: 'eur', valeur: 100 } },
  { lignes: [{ prix: 1, quantite: 1, taux: 550 }], remise: null },
  { lignes: [{ prix: 2500, quantite: 2, taux: 2000, remise: { type: 'pct', valeur: 10000 } }], remise: null },
  { lignes: [{ prix: 999, quantite: 3, taux: 2000, remise: { type: 'pct', valeur: 3333 } }, { prix: 1490, quantite: 1, taux: 550 }], remise: { type: 'pct', valeur: 1250 } },
  { lignes: [{ prix: 9999999, quantite: 1, taux: 2000 }], remise: { type: 'pct', valeur: 9999 } },
  { lignes: [{ prix: 3333332, quantite: 3, taux: 2000 }, { prix: 3, quantite: 1, taux: 550 }], remise: { type: 'eur', valeur: 9999999 } },
];
for (let i = 0; i < 3000; i++) {
  const lignes = [];
  for (let j = 0, n = 1 + hasard(8); j < n; j++) {
    const prix = hasard(20000);
    const quantite = 1 + hasard(5);
    lignes.push({ prix, quantite, taux: taux[hasard(5)], remise: remiseAuHasard(prix * quantite) });
  }
  cas.push({ lignes, remise: remiseAuHasard(50000) });
}
const sortie = cas.map(c => ({ cas: c, js: C.ticket(c.lignes, c.remise) }));
let incoherents = 0;
for (const { js } of sortie) {
  const lignes = js.lignes.reduce((s, l) => s + l.final, 0);
  const parTaux = js.tva.reduce((s, t) => s + t.ttc, 0);
  if (lignes !== js.total || parTaux !== js.total || js.total_ht + js.total_tva !== js.total || js.total < 0) { incoherents++; }
}
require('fs').writeFileSync(process.argv[2], JSON.stringify(sortie));
console.log(cas.length + ' tickets calculés en JavaScript ; invariants (lignes = total = somme des taux, HT + TVA = TTC) : ' + (incoherents ? incoherents + ' ÉCHEC(S)' : 'OK'));
process.exit(incoherents ? 1 : 0);
