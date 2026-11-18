#!/usr/bin/env bash
# Tests de bout en bout sur le site de développement (https://caissebarber.gestionh.com, base BARBER_dev).
# Écrit de vrais tickets de test dans BARBER_dev.
# Usage : BP_TEST_EMAIL=… BP_TEST_MDP=… tests/api.sh <dossier temporaire>
set -u
U=${BP_TEST_URL:-https://caissebarber.gestionh.com}
TMP=$1
J=$TMP/cookies.txt
: > "$J"
OK=0; KO=0
ok()  { if [ "$1" = "$2" ]; then OK=$((OK+1)); echo "  OK    $3"; else KO=$((KO+1)); echo "  ÉCHEC $3 → obtenu [$1], attendu [$2]"; fi; }
has() { if printf '%s' "$1" | grep -q -- "$2"; then OK=$((OK+1)); echo "  OK    $3"; else KO=$((KO+1)); echo "  ÉCHEC $3 (absent : $2) [$(printf '%s' "$1" | head -c 200)]"; fi; }

T=$(curl -s -c "$J" -b "$J" "$U/login.php" | grep -oP 'name="csrf" value="\K[^"]+')
curl -s -c "$J" -b "$J" -o /dev/null --data-urlencode "csrf=$T" --data-urlencode "email=$BP_TEST_EMAIL" --data-urlencode "mot_de_passe=$BP_TEST_MDP" "$U/login.php"
PAGE=$(curl -s -b "$J" "$U/")
printf '%s' "$PAGE" > "$TMP/page.html"
CSRF=$(printf '%s' "$PAGE" | grep -oP '<meta name="csrf" content="\K[^"]+')
A() { curl -s -b "$J" -c "$J" -H "X-CSRF-Token: $CSRF" -H 'Content-Type: application/json' -w ' [%{http_code}]' "$U/api/caisse.php?action=$1" ${2:+--data "$2"}; }

echo "Accès"
has "$PAGE" 'window.BARBERPAY' "page de caisse (connecté)"
ok "$(curl -s -o /dev/null -w '%{http_code}' "$U/api/caisse.php?action=etat")" "401" "API sans session → 401"
ok "$(curl -s -b "$J" -o /dev/null -w '%{http_code}' -H 'Content-Type: application/json' --data '{}' "$U/api/caisse.php?action=verrouiller")" "403" "POST sans jeton CSRF → 403"
for chemin in app/bootstrap.php app/src/Ventes.php app/migrations/tenant/0004_fiscal.sql docs/PLAN-V1-BarberPay.md tests/api.sh .git/config; do
  ok "$(curl -s -o /dev/null -w '%{http_code}' "$U/$chemin")" "403" "fichier interne bloqué : $chemin"
done

GID=$(A etat | grep -oP '"operateur":\{"id":\K\d+')
ART=$(printf '%s' "$PAGE" | grep -oP '"id":\K\d+(?=,"type":"service")' | head -1)
PRIX=$(printf '%s' "$PAGE" | grep -oP '"id":'"$ART"',"type":"service","nom":"[^"]*","prix":\K\d+')
echo "Encaissement (article $ART à $PRIX centimes)"
TK='{"barbierId":'$GID',"remise":null,"lignes":[{"articleId":'$ART',"type":"service","nom":"x","prix":'$PRIX',"taux":2000,"quantite":1,"barbierId":'$GID',"remise":null}]}'
CLE=$(cat /proc/sys/kernel/random/uuid)
R=$(A encaisser '{"ticket":'"$TK"',"paiements":[{"moyen":"cb","montant":'$PRIX'}],"totalAffiche":'$PRIX',"cle":"'$CLE'"}')
has "$R" '"ok":true' "encaissement par carte"
ID=$(printf '%s' "$R" | grep -oP '"id":\K\d+'); JETON=$(printf '%s' "$R" | grep -oP '"jeton":"\K[0-9a-f]+')
has "$(A encaisser '{"ticket":'"$TK"',"paiements":[{"moyen":"cb","montant":'$PRIX'}],"totalAffiche":'$PRIX',"cle":"'$CLE'"}')" '"deja":true' "même clé renvoyée : pas de second ticket"
has "$(A encaisser '{"ticket":'"$TK"',"paiements":[{"moyen":"cb","montant":1}],"totalAffiche":1}')" '\[409\]' "total affiché faux → 409"
has "$(A encaisser '{"ticket":'"$TK"',"paiements":[{"moyen":"especes","montant":'$PRIX',"recu":'$((PRIX+1000))'}],"totalAffiche":'$PRIX'}')" '"rendu":1000' "espèces : 10 € rendus"
echo "Ticket en ligne"
ok "$(curl -s -o /dev/null -w '%{http_code}' "$U/t/$JETON")" "200" "ticket consultable par son lien"
ok "$(curl -s -D - -o /dev/null "$U/t/$JETON" | grep -ci '^set-cookie')" "0" "aucun cookie posé au client"
ok "$(curl -s -o /dev/null -w '%{http_code}' "$U/t/0123456789abcdef0123456789abcdef")" "404" "lien inconnu → 404"
has "$(curl -s "$U/t/$JETON")" 'Signature' "mentions techniques (version, signature) sur le ticket"
echo "Annulation"
R=$(A annuler '{"ticketId":'$ID',"motif":"Test automatique"}')
has "$R" '"ok":true' "annulation par le gérant"
has "$(A annuler '{"ticketId":'$ID',"motif":"encore"}')" 'déjà été annulé' "deuxième annulation refusée"
has "$(curl -s "$U/t/$JETON")" 'Ticket annulé par le ticket' "le ticket d'origine affiche son annulation"
echo "Historique, duplicata, recette"
H=$(curl -s -b "$J" "$U/historique.php")
has "$H" "N° " "historique du jour"
has "$H" "data-duplicata=\"$ID\"" "bouton Réimprimer sur le ticket encaissé"
R=$(A duplicata '{"ticketId":'$ID'}'); NDUP=$(printf '%s' "$R" | grep -oP '"numero":\K\d+')
has "$R" '"ok":true' "duplicata enregistré (n° $NDUP)"
has "$(curl -s "$U/t/$JETON?duplicata=$NDUP")" "DUPLICATA n° $NDUP" "le duplicata porte la mention DUPLICATA"
ok "$(curl -s "$U/t/$JETON?duplicata=999" | grep -c 'DUPLICATA n°')" "0" "numéro de duplicata inexistant : pas de mention"
has "$(curl -s -b "$J" "$U/historique.php?numero=1")" "N° 000001" "recherche par numéro"
REC=$(curl -s -b "$J" "$U/recette.php")
has "$REC" "Chiffre d'affaires net" "recette du jour"
ok "$(curl -s -o /dev/null -w '%{redirect_url}' "$U/recette.php")" "$U/login.php" "recette sans session → connexion"
ok "$(curl -s -o /dev/null -w '%{redirect_url}' "$U/z.php?id=1")" "$U/login.php" "Z sans session → connexion"

echo "Exports et conformité (admin)"
AUJ=$(date +%Y-%m-%d); DEB=$(date +%Y-%m-01)
has "$(curl -s -b "$J" "$U/admin/exports.php")" "Récapitulatif URSSAF" "page Exports"
has "$(curl -s -b "$J" "$U/admin/livre.php?debut=$DEB&fin=$AUJ")" "Livre des recettes" "livre des recettes à l'écran"
ok "$(curl -s -b "$J" -o /dev/null -w '%{content_type}' "$U/admin/telecharger.php?quoi=livre&debut=$DEB&fin=$AUJ")" "text/csv; charset=UTF-8" "livre des recettes en CSV"
has "$(curl -s -b "$J" "$U/admin/telecharger.php?quoi=ereporting-json&debut=$DEB&fin=$AUJ")" '"totaux_journaliers"' "e-reporting en JSON"
curl -s -b "$J" -o "$TMP/fiscal.zip" "$U/admin/telecharger.php?quoi=fiscal&debut=$DEB&fin=$AUJ"
has "$(unzip -l "$TMP/fiscal.zip" 2>/dev/null)" "cle_publique_ed25519.txt" "export pour contrôle fiscal (ZIP)"
ok "$(curl -s -b "$J" -o /dev/null -w '%{redirect_url}' "$U/admin/telecharger.php?quoi=fiscal&debut=2026-13-01&fin=$AUJ")" "$U/admin/exports.php" "période invalide refusée"
C=$(curl -s -b "$J" "$U/admin/conformite.php"); CC=$(printf '%s' "$C" | grep -oP 'name="csrf" value="\K[^"]+' | head -1)
has "$(curl -s -b "$J" -L --data-urlencode "csrf=$CC" --data-urlencode action=integrite "$U/admin/conformite.php")" "Intègre" "contrôle d'intégrité depuis l'admin"
has "$(curl -s -b "$J" "$U/admin/attestation.php")" "Attestation individuelle" "attestation de l'éditeur"
ok "$(curl -s -o /dev/null -w '%{redirect_url}' "$U/admin/telecharger.php?quoi=fiscal&debut=$DEB&fin=$AUJ")" "$U/login.php" "export sans session → connexion"

echo "Tickets en attente"
R=$(A attente_mettre '{"ticket":'"$TK"'}'); AID=$(printf '%s' "$R" | grep -oP '"id":\K\d+')
has "$R" '"ok":true' "mise en attente"
has "$(A attente_reprendre '{"id":'$AID'}')" '"ok":true' "reprise"
has "$(A attente_reprendre '{"id":'$AID'}')" '\[404\]' "reprise en double → 404"
echo "Verrouillage"
has "$(A verrouiller '{}')" '"ok":true' "verrouiller"
has "$(A attente_liste)" '\[423\]' "caisse verrouillée → 423"
has "$(A encaisser '{"ticket":'"$TK"',"paiements":[{"moyen":"cb","montant":'$PRIX'}],"totalAffiche":'$PRIX'}')" '\[423\]' "pas d'encaissement caisse verrouillée"

echo
echo "$((OK+KO)) tests, $OK réussis$([ $KO -gt 0 ] && echo ", $KO ÉCHEC(S)")"
[ $KO -eq 0 ]
