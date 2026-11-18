#!/usr/bin/env bash
# BarberPay — lance tous les tests.
#
#   tests/run.sh                 unitaires + calculs + module fiscal (sans site web)
#   tests/run.sh tout            + API et écran de caisse sur le site de dev (identifiants nécessaires)
#
# Variables pour « tout » : BP_TEST_EMAIL, BP_TEST_MDP (gérant du compte caissebarber).
# Le navigateur simulé demande jsdom : npm install --prefix tests
set -u
cd "$(dirname "$0")/.."
PHP="php -d short_open_tag=1"
TMP=$(mktemp -d)
trap 'rm -rf -- "$TMP"' EXIT
ECHECS=0
etape() { echo; echo "━━ $1"; }
lancer() { "$@" || ECHECS=$((ECHECS+1)); }

etape "Tests unitaires (PHP)"
lancer $PHP tests/unit.php
etape "Calcul : PHP et JavaScript identiques"
lancer node tests/calcul.js "$TMP/calcul.json" && lancer $PHP tests/calcul.php "$TMP/calcul.json"
etape "Module fiscal (base de test BARBER_dev2)"
lancer $PHP tests/fiscal.php

if [ "${1:-}" = "tout" ]; then
  if [ -z "${BP_TEST_EMAIL:-}" ] || [ -z "${BP_TEST_MDP:-}" ]; then
    echo "BP_TEST_EMAIL et BP_TEST_MDP sont nécessaires pour les tests du site." >&2
    exit 2
  fi
  etape "API et ticket en ligne (site de dev)"
  lancer bash tests/api.sh "$TMP"
  etape "Écran de caisse (navigateur simulé)"
  if [ -d tests/node_modules/jsdom ]; then
    lancer node tests/ui.test.js "$TMP/page.html" "$PWD"
  else
    echo "  (ignoré : installez jsdom avec « npm install --prefix tests »)"
  fi
  etape "Intégrité de la base de dev"
  lancer app/cli/barberpay integrite --compte=caissebarber
  # Les tests de verrouillage laissent la caisse de dev verrouillée : rien d'autre à nettoyer.
fi

echo
if [ $ECHECS -eq 0 ]; then echo "✅ Tous les tests sont passés."; else echo "❌ $ECHECS série(s) en échec."; fi
exit $ECHECS
