#!/usr/bin/env bash
# Détection grossière de secrets committés (clés privées, jetons connus, APP_KEY, mots de passe en dur dans .env).
# Ne remplace pas un vrai outil (gitleaks) : c'est un garde-fou sans dépendance. Usage : scripts/scan-secrets.sh [dossier]
set -uo pipefail
ROOT="${1:-.}"
status=0
check() {
  local label="$1" pattern="$2"
  local hits
  hits="$(grep -rIEn --exclude-dir={.git,node_modules,vendor,build,.dart_tool,backend} --exclude='*.lock' --exclude='scan-secrets.sh' --exclude='*.tar.gz' -e "$pattern" "$ROOT" 2>/dev/null || true)"
  if [ -n "$hits" ]; then echo "== $label"; echo "$hits" | cut -c1-200; status=1; fi
}
check "clé privée"                 '-----BEGIN (RSA |EC |OPENSSH |DSA |PGP )?PRIVATE KEY'
check "jeton GitHub"               'gh[pousr]_[A-Za-z0-9]{36,}'
check "clé AWS"                    'AKIA[0-9A-Z]{16}'
check "jeton Slack"                'xox[baprs]-[A-Za-z0-9-]{10,}'
check "APP_KEY réelle"             '^APP_KEY=base64:[A-Za-z0-9+/=]{20,}'
check "jeton d'agent en dur"       'wza_[A-Za-z0-9]{24,}'
# .env réels interdits dans le dépôt (les .example sont autorisés).
if find "$ROOT" -name '.env' -o -name '.env.production' -o -name '.env.local' 2>/dev/null | grep -v node_modules | grep -q .; then
  echo "== fichier .env présent"; find "$ROOT" \( -name '.env' -o -name '.env.production' -o -name '.env.local' \) -not -path '*/node_modules/*'; status=1
fi
[ $status -eq 0 ] && echo "Aucun secret détecté." || echo "Secrets potentiels détectés : à examiner AVANT de pousser." >&2
exit $status
