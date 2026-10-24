#!/usr/bin/env bash
# Génère le squelette Laravel dans /var/www/html (= ./backend) puis applique /overlay (= ./backend-overlay).
# À lancer dans le conteneur : make setup
set -euo pipefail

APP=/var/www/html

if [ ! -f "$APP/artisan" ]; then
  tmp=$(mktemp -d)
  composer create-project laravel/laravel:^12.0 "$tmp" --no-interaction --prefer-dist
  cp -a "$tmp"/. "$APP"/
  rm -rf "$tmp"
fi

cd "$APP"

# Sanctum : on n'utilise PAS `artisan install:api` (il publie puis EXÉCUTE des migrations par défaut,
# qui créeraient une table users à clé entière en conflit avec les nôtres). Notre overlay fournit
# routes/api.php et la migration personal_access_tokens (uuidMorphs).
grep -q '"laravel/sanctum"' composer.json || composer require laravel/sanctum --no-interaction
# Moteur PDF (Phase 5) : arabe, hausa et QR codes. Nécessite les extensions gd et mbstring (présentes dans docker/php).
grep -q '"mpdf/mpdf"' composer.json || composer require mpdf/mpdf --no-interaction
grep -q '"mpdf/qrcode"' composer.json || composer require mpdf/qrcode --no-interaction

# Migration par défaut des utilisateurs remplacée par les nôtres (UUID, tenant_id)
rm -f database/migrations/0001_01_01_000000_create_users_table.php
rm -f database/migrations/*_create_personal_access_tokens_table.php

# Overlay MY WIFIZONE (écrase routes/api.php, bootstrap/app.php, User, etc.)
cp -a /overlay/. "$APP"/

# Le .env du squelette Laravel cible SQLite : on le remplace par le nôtre (PostgreSQL/Redis) tant qu'il n'a pas été adapté.
if ! grep -q '^DB_CONNECTION=pgsql' .env 2>/dev/null; then
  cp .env.example .env
fi
# Ne régénère pas la clé à chaque `make setup` : cela invaliderait les données chiffrées existantes.
grep -q '^APP_KEY=base64:' .env || php artisan key:generate --force --no-interaction
composer dump-autoload --no-interaction

# Développement : php-fpm (www-data) doit pouvoir écrire dans storage/ alors que les fichiers appartiennent à root.
chmod -R a+rwX storage bootstrap/cache

echo "Backend prêt. Lancez : make migrate  puis  make test"
