# Guide de déploiement et d'exploitation

Cible : un serveur Linux (2 vCPU / 4 Go de RAM suffisent pour démarrer) avec Docker, un nom de domaine pointant vers lui, ports 80/443 ouverts.
**Aucune des étapes ci-dessous n'a été exécutée sur un vrai serveur** ; les scripts de sauvegarde/restauration l'ont été sur PostgreSQL 16 avec un schéma de test.

## 1. Préparer
```bash
git clone <dépôt> && cd mywifizone
cp .env.production.example .env.production && chmod 600 .env.production
# Renseigner : APP_DOMAIN, APP_URL, DB_PASSWORD, REDIS_PASSWORD, METRICS_TOKEN (openssl rand -hex 32)
make setup                                    # génère backend/ (Laravel + overlay + mpdf)
docker compose run --rm app php artisan key:generate --show   # copier dans APP_KEY (puis la sauvegarder à part)
```

## 2. Déployer
```bash
APP_VERSION=1.0.0 scripts/deploy.sh
```
Le script construit les images, **sauvegarde la base**, applique les migrations, met en cache config/routes, relance et attend `/api/v1/health/ready`.
Première fois seulement : `docker compose -f docker-compose.prod.yml --env-file .env.production run --rm app php artisan db:seed --force` (rôles, devises, formules) puis `php artisan wifizone:create-super-admin <email>`.

## 3. Vérifier
```bash
scripts/e2e-smoke.sh https://api.example.com
```
Crée un compte de test et déroule : santé, disponibilité, inscription, connexion, site, tableau de bord, refus sans jeton, métriques non exposées.

## 4. Observabilité
- **Journaux** : JSON sur stderr (`docker compose logs`). Champs : `request_id` (renvoyé aussi dans `X-Request-Id`), `tenant_id`, `user_id`, `route`, `status`, `duration_ms`. Les clés sensibles sont masquées.
- **Santé** : `/api/v1/health` (vivant) et `/api/v1/health/ready` (base + cache ; 503 si dégradé).
- **Métriques** : `/api/v1/internal/metrics` (Prometheus, jeton Bearer `METRICS_TOKEN`) — **bloqué par Caddy depuis Internet** ; à scraper depuis le réseau Docker (`nginx:80`). Compteurs : tenants, routeurs en/hors ligne, commandes en attente/échouées, paiements coincés, commandes du portail à traiter, vouchers non synchronisés, tâches en échec, taille de la file.
- **Alertes** : `monitor:check` (toutes les 5 min) écrit `ALERT <règle>` en `error` (critique : argent) ou `warning`. Brancher un outil sur ces lignes (Loki/Grafana, Better Stack, ou `docker compose logs | grep ALERT`). Suivi d'erreurs externe (Sentry…) : non intégré, à ajouter avec son DSN si souhaité.

| Alerte | Sens | Action |
|---|---|---|
| `portal_orders_need_attention` (critique) | client payé, plus de ticket | livrer un ticket à la main ou rembourser ; réapprovisionner |
| `payments_stuck` (critique) | paiement sans issue au-delà du délai | vérifier chez le fournisseur ; `payments:expire-stale` |
| `failed_jobs` / `queue_backlog` | file bloquée | `docker compose logs worker`, relancer le worker |
| `vouchers_sync_failed` | tickets non créés sur le routeur | relancer via `/routers/{id}/sync` |
| `commands_stuck` / `routers_offline` | agent muet | alimentation, Internet du site, jeton d'agent |

## 5. Sauvegardes et restauration
- Le service `backup` fait un `pg_dump` toutes les 24 h dans le volume `backups` (rotation `BACKUP_RETENTION_DAYS`, chiffrement AES-256 si `BACKUP_PASSPHRASE_FILE`).
- **Copiez ce volume hors du serveur** (rclone/rsync vers un stockage distant) : une sauvegarde sur le même disque ne protège pas d'une panne du disque.
- Test de restauration (à planifier chaque semaine) :
  ```bash
  docker compose -f docker-compose.prod.yml --env-file .env.production run --rm backup /scripts/restore-test.sh
  ```
  Il restaure la dernière sauvegarde dans une base temporaire, vérifie les tables clés et la supprime.
- Restauration réelle (après incident) : arrêter `app worker scheduler`, puis `restore-postgres.sh <fichier> <base> --force`, relancer. Garder la `APP_KEY` d'origine.

## 6. Mise à jour et retour arrière
`APP_VERSION=<nouvelle> scripts/deploy.sh`. Les migrations sont **additives** (nouvelles tables/colonnes/index) : revenir à l'image précédente reste possible sans défaire la base. Pour un retour arrière complet : restaurer la sauvegarde prise juste avant le déploiement.

## 7. Performance (état réel)
- Index présents sur les requêtes chaudes (ventes par jour/agent/site/profil, vouchers par routeur/profil/statut, commandes d'agent, sessions) ; trois index ajoutés pour les sondes (migration `2026_10_22_000001`).
- Opcache sans revalidation, autoloader optimisé, config/routes en cache, `queue:work --max-time`.
- **Aucun test de charge n'a été exécuté.** `scripts/load-test.js` (k6) fournit un point de départ sur les routes publiques : à lancer sur une recette avant l'ouverture commerciale.

## Routeurs en connexion directe

Le serveur (php-fpm et `worker`) appelle l'API REST HTTPS des routeurs : les connexions **sortantes** vers le port 443 (ou celui saisi) des routeurs doivent être permises par le pare-feu de l'hébergeur. Le `scheduler` lance `routers:poll-rest` chaque minute (commandes en attente, santé, sessions). Le `worker` doit tourner (`QUEUE_CONNECTION=redis`) : sans lui, les commandes ne partent qu'à la minute suivante.

- `ROUTER_REST_ALLOW_PRIVATE=false` (défaut) : refuse les adresses privées/réservées (192.168/16, 10/8, 172.16/12, 100.64/10, 127/8, 169.254/16…). Ne mettre `true` que si les routeurs sont joints par un VPN privé (WireGuard) **et** que tous les clients sont de confiance.
- Communiquer aux clients l'adresse IP **sortante** de votre serveur pour qu'ils l'autorisent seule sur le port 443 de leur routeur.
- Seul le compte dédié `wifizone` est stocké (chiffré avec `APP_KEY`) ; changer `APP_KEY` le rend illisible : prévoir « Reconnecter » pour chaque routeur.
