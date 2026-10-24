#!/usr/bin/env bash
# Déploiement (serveur de production, après `make setup` du code à publier). Étapes :
#   1. construit les images versionnées  2. sauvegarde la base  3. applique les migrations  4. relance  5. vérifie /health/ready
# Usage : APP_VERSION=1.0.0 scripts/deploy.sh
# Retour arrière : relancer avec APP_VERSION=<précédente> ; les migrations sont additives (voir docs/deployment-guide.md).
set -euo pipefail
cd "$(dirname "$0")/.."

: "${APP_VERSION:?définir APP_VERSION (ex. 1.0.0)}"
[ -f .env.production ] || { echo ".env.production introuvable (copier .env.production.example)" >&2; exit 1; }
export APP_VERSION
DC=(docker compose -f docker-compose.prod.yml --env-file .env.production)

docker build -f docker/php/Dockerfile.prod -t "mywifizone-app:$APP_VERSION" .
docker build -f docker/nginx/Dockerfile.prod -t "mywifizone-nginx:$APP_VERSION" .

"${DC[@]}" up -d postgres redis
"${DC[@]}" run --rm backup /scripts/backup-postgres.sh          # sauvegarde AVANT toute migration

"${DC[@]}" run --rm app php artisan migrate --force
"${DC[@]}" run --rm app php artisan config:cache
"${DC[@]}" run --rm app php artisan route:cache
"${DC[@]}" up -d --remove-orphans

echo "Attente de /health/ready..."
for i in $(seq 1 30); do
  if "${DC[@]}" exec -T nginx wget -qO- http://127.0.0.1/api/v1/health/ready | grep -q '"ready"'; then
    echo "Déploiement OK (version $APP_VERSION)"; exit 0
  fi
  sleep 2
done
echo "ÉCHEC : l'application n'est pas prête. Retour arrière : APP_VERSION=<précédente> scripts/deploy.sh" >&2
exit 1
