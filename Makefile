.PHONY: setup up down migrate seed test lint logs smoke e2e backup restore-test secrets

DC=docker compose
RUN=$(DC) run --rm app

setup:   ## Première installation : copie .env, génère le backend Laravel, applique l'overlay
	@[ -f .env ] || cp .env.example .env
	mkdir -p backend
	$(DC) build
	$(RUN) bash /scripts/setup-backend.sh

up:
	$(DC) up -d

down:
	$(DC) down

migrate:
	$(DC) up -d postgres redis
	$(RUN) php artisan migrate --seed --force

test:
	$(RUN) php artisan test

smoke:   ## Logique de domaine pure (Money, remises, machine à états, signatures, mock), sans Laravel ni Composer
	@if command -v php >/dev/null 2>&1; then php scripts/smoke-domain.php; \
	else docker run --rm -v "$$PWD":/w -w /w php:8.3-cli php scripts/smoke-domain.php; fi

lint:
	$(RUN) ./vendor/bin/pint --test

logs:
	$(DC) logs -f app nginx worker

e2e:     ## Parcours de fumée sur la pile qui tourne (make up d'abord)
	bash scripts/e2e-smoke.sh http://localhost:$${APP_PORT:-8080}

backup:  ## Sauvegarde PostgreSQL (dev) dans ./backups
	mkdir -p backups
	$(DC) run --rm -v "$$PWD/backups:/backups" -v "$$PWD/scripts:/scripts:ro" -e PGHOST=postgres -e PGUSER -e PGPASSWORD -e PGDATABASE postgres sh /scripts/backup-postgres.sh

restore-test:  ## Restaure la dernière sauvegarde dans une base temporaire et vérifie
	$(DC) run --rm -v "$$PWD/backups:/backups" -v "$$PWD/scripts:/scripts:ro" -e PGHOST=postgres -e PGUSER -e PGPASSWORD -e PGDATABASE postgres sh /scripts/restore-test.sh

secrets: ## Cherche des secrets committés
	bash scripts/scan-secrets.sh .
