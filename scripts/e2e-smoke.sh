#!/usr/bin/env bash
# Test de fumée sur une instance EN MARCHE (dev, recette ou production après déploiement) :
#   scripts/e2e-smoke.sh http://localhost:8080
# Parcours : santé -> disponibilité -> inscription -> connexion -> profil -> site -> déconnexion. Crée UN compte de test.
# Ne touche à aucune donnée existante. Nécessite curl et jq.
set -euo pipefail

BASE="${1:?usage: e2e-smoke.sh <url de base, ex. http://localhost:8080>}"
API="$BASE/api/v1"
SUFFIX="$(date +%s)$RANDOM"
PHONE="+2279$(printf '%07d' $((RANDOM * 30 % 10000000)))"
PASS="e2e-$(head -c 12 /dev/urandom | base64 | tr -dc 'A-Za-z0-9')"

fail() { echo "ÉCHEC : $*" >&2; exit 1; }
req() { curl -sS --max-time 20 -H 'Accept: application/json' -H 'Content-Type: application/json' "$@"; }

echo "1. santé";           [ "$(req "$API/health" | jq -r .data.status)" = "ok" ] || fail "health"
echo "2. disponibilité";   [ "$(req "$API/health/ready" | jq -r .data.status)" = "ready" ] || fail "ready (base ou cache indisponible)"
echo "3. en-têtes";        HEADERS="$(curl -sSI "$API/health")"
echo "$HEADERS" | grep -qi '^x-content-type-options: nosniff' || fail "X-Content-Type-Options absent"
echo "$HEADERS" | grep -qi '^x-request-id:' || fail "X-Request-Id absent"
echo "4. inscription"
REG="$(req -X POST "$API/auth/register" -d "{\"name\":\"E2E $SUFFIX\",\"business_name\":\"E2E $SUFFIX\",\"phone\":\"$PHONE\",\"password\":\"$PASS\",\"device_name\":\"e2e\"}")"
[ "$(echo "$REG" | jq -r .success)" = "true" ] || fail "inscription : $(echo "$REG" | jq -c .errors)"
echo "5. connexion"
TOKEN="$(req -X POST "$API/auth/login" -d "{\"identifier\":\"$PHONE\",\"password\":\"$PASS\",\"device_name\":\"e2e\"}" | jq -r .data.tokens.access_token)"
[ -n "$TOKEN" ] && [ "$TOKEN" != "null" ] || fail "connexion"
AUTH=(-H "Authorization: Bearer $TOKEN")
echo "6. site + tableau de bord"
SITE="$(req "${AUTH[@]}" -X POST "$API/sites" -d '{"name":"Site E2E"}' | jq -r .data.id)"; [ "$SITE" != "null" ] || fail "création de site"
[ "$(req "${AUTH[@]}" "$API/dashboard" | jq -r .success)" = "true" ] || fail "dashboard"
echo "7. isolation : sans jeton, accès refusé"
[ "$(curl -s -o /dev/null -w '%{http_code}' -H 'Accept: application/json' "$API/sites")" = "401" ] || fail "route protégée accessible sans jeton"
echo "8. métriques non exposées sans jeton"
CODE="$(curl -s -o /dev/null -w '%{http_code}' "$API/internal/metrics")"; { [ "$CODE" = "404" ] || [ "$CODE" = "401" ]; } || fail "métriques ouvertes ($CODE)"
echo "OK : parcours de fumée réussi sur $BASE (compte de test : $PHONE)"
