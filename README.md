# MY WIFIZONE — Manage • Sell • Connect

Monorepo. **Phases 1 à 4 écrites** : fondations (Laravel, PostgreSQL, Redis, auth, multi-tenant, RBAC, squelette Flutter) puis SaaS (formules, abonnements, factures, paiements via adaptateurs, mocks, webhooks idempotents, Super Admin), puis WiFi (routeurs, agent MikroTik sortant, profils, vouchers, sessions) puis ventes, caisse, rapports et agents, avec leurs écrans mobiles.

> **Statut de validation** : le code a été écrit et relu sans pouvoir exécuter PHPUnit ni Flutter (dépendances inaccessibles dans l'environnement de rédaction). Ont été exécutés : lint PHP, `scripts/smoke-domain.php` (36 vérifications sur la logique pure) et la concordance routes ↔ OpenAPI. **La suite PHPUnit (`make test`) est à lancer chez vous avant de continuer.**

## Structure

```
mywifizone/
├─ backend-overlay/        # Code MY WIFIZONE appliqué sur un squelette Laravel 12 neuf
│  ├─ app/{Domain,Infrastructure,Services,Http,Models,...}
│  ├─ docs/openapi.yaml    # Contrat OpenAPI (testé : doit décrire exactement les routes)
│  └─ tests/{Unit,Feature}
├─ backend/                # Généré par `make setup` (ignoré par git)
├─ mobile/                 # Flutter (design system, i18n fr/en/ar/ha, RTL, login)
├─ docker/  scripts/  docs/{adr,payment-adapter-guide.md}
├─ docker-compose.yml      # app, nginx, postgres, redis, worker, scheduler
└─ Makefile
```

## Installation (backend)

Prérequis : Docker.

```bash
make setup      # .env, build, Laravel 12 + Sanctum, applique l'overlay
make migrate    # migrations + rôles, devises, formules, fournisseurs
make test       # php artisan test (SQLite en mémoire)
make up         # API : http://localhost:8080/api/v1
make smoke      # logique de domaine pure, sans Laravel ni Composer
docker compose run --rm app php artisan wifizone:create-super-admin vous@exemple.com
```

## Installation (mobile)

```bash
cd mobile
flutter create . --project-name mywifizone --platforms=android,ios
flutter pub get && flutter gen-l10n && flutter test
flutter run --dart-define=API_BASE_URL=http://10.0.2.2:8080/api/v1
```

## API

Contrat complet : [`backend-overlay/docs/openapi.yaml`](backend-overlay/docs/openapi.yaml) (114 opérations). Enveloppe `{ success, data, meta, errors[{code, field?, message}] }`.
Groupes : `auth/*`, `tenants/me`, `sites`, `subscription*`, `invoices`, `plans` (public), `payments/webhooks/{provider}` (signé), `routers`, `profiles`, `vouchers`, `sessions`, `agent/*` (connecteur), `sales`, `cash-registers`, `cash-sessions`, `reports/sales`, `dashboard`, `agents`, `admin/*` (Super Admin).

## Règles d'abonnement

| Règle | Valeur |
|---|---|
| Essai gratuit | **14 jours** (confirmé), surchargeable par formule, prolongeable par un code `TRIAL_EXTENSION` |
| Prix de lancement | STARTER 2 000 · PRO 5 000 · BUSINESS 10 000 XOF/mois, modifiables par le Super Admin |
| Prix annuel | Non proposé tant que le Super Admin n'a pas fixé `yearly_price` |
| Période de grâce | 3 jours après échéance (surchargeable par formule) ; l'accès est conservé |
| Statuts | TRIAL → ACTIVE → PAST_DUE → EXPIRED ; PENDING (premier paiement en cours), SUSPENDED (Super Admin), CANCELLED |
| Abonnement inactif | Écritures refusées (`402`), lectures et paiement toujours possibles |
| Montants | Entiers en unités mineures + code devise, jamais de décimaux |

Tâches planifiées (service `scheduler`) : `subscriptions:process` (01:00), `payments:expire-stale` (10 min), `network:maintain` (1 min : routeurs hors ligne, baux de commandes) et `vouchers:expire` (5 min).

## Paiements

Seul le fournisseur **`mock`** (fictif, dev/tests) est branché. Wave, Nita, Airtel Money et carte sont des adaptateurs **non branchés** : aucun endpoint ni format de webhook n'est supposé. Voir [`docs/payment-adapter-guide.md`](docs/payment-adapter-guide.md).
Garanties : webhooks signés (corps brut, horodatage anti-rejeu), idempotence par `(fournisseur, event_id)`, montant et devise vérifiés avant toute activation, machine à états des transactions, aucune donnée de carte.

## Hypothèses à valider

- Numérotation des factures `INV-AAAAMMJJ-XXXXXX` : unique mais **non séquentielle** (une numérotation fiscale continue demandera un compteur dédié).
- Pendant une suspension, le temps compte : ni l'essai ni la période payée ne sont prolongés.
- Le quota d'un code de remise est décompté au paiement : une course entre deux checkouts peut le dépasser légèrement (le code d'extension d'essai, lui, est réservé atomiquement).
- PRO : 10 agents ; BUSINESS : limites illimitées (non chiffré par le cahier des charges).
- Pas de prorata lors d'un changement de formule : la nouvelle période commence au paiement.

## Reste à faire

- Phase 1 : reset du mot de passe, vérification e-mail/téléphone, MFA.
- Phase 2 : réconciliation active auprès d'un fournisseur réel (nécessite son API officielle), PDF des factures/reçus (Phase 5), notifications.
- Mobile : écrans abonnement et factures.
- Phase 3 : **non testée sur un vrai routeur MikroTik** (l'agent est validé uniquement contre un RouterOS simulé). Voir [`docs/mikrotik-setup-guide.md`](docs/mikrotik-setup-guide.md) et [ADR 0005](docs/adr/0005-agent-sortant-mikrotik.md).
- Phase 4 : règles et limites dans [ADR 0006](docs/adr/0006-ventes-caisse-rapports.md). Les références de paiement saisies (Mobile money, banque) ne sont **pas vérifiées** auprès d'un opérateur.
- Phase 5 (PDF, QR, partage) : voir [ADR 0007](docs/adr/0007-documents-pdf-qr.md). mPDF est installé par `make setup` ; le rendu réel (arabe, QR, mise en page) n'est **pas vérifié** tant que les PDF d'exemple de la CI (artefact `sample-pdfs`) n'ont pas été relus. Traductions ar/ha à relire. Facture sans mentions fiscales. Mobile : export/partage/impression des tickets (Tickets → icône imprimante), reçu PDF après une vente, export CSV (Rapports), écran Personnalisation (Plus) ; le **logo** se téléverse pour l'instant par l'API seulement ; plugins `share_plus`, `path_provider`, `printing` non testés (versions à confirmer au premier `flutter pub get`).
- Phase 6 (International) : voir [ADR 0008](docs/adr/0008-international-devises.md). Mobile : écrans *Région et devise* et *Taux de change* (Plus), saisie des prix en unités majeures, décimales selon le catalogue. Traductions ar/ha à relire ; langue non mémorisée entre deux lancements.
- Phase 7 (portail captif, achat direct, services) : voir [ADR 0009](docs/adr/0009-portail-captif.md) et [modèle login.html](docs/mikrotik-portal-login.html). **Non testé sur routeur réel**, aucun fournisseur de paiement réel (mock seulement), reversement aux propriétaires non conçu.
- Phase 8 (durcissement) : [ADR 0010](docs/adr/0010-durcissement-exploitation.md), [checklist sécurité](docs/security-checklist.md), [guide de déploiement](docs/deployment-guide.md), [notes de version](docs/release-notes.md). Sauvegardes/restauration vérifiées sur PostgreSQL 16 (schéma de test) ; le reste (PHPUnit, pile Docker, serveur réel, charge) **jamais exécuté**.\n- V1 candidate complète : voir *Validations restantes* dans les notes de version.

## APK de test (GitHub Actions)

- `.github/workflows/android-apk.yml` : génère le projet Android, construit l'APK `release` (signé avec la clé de debug : test uniquement) et le publie en artefact + Release « prerelease ». Lancement : onglet *Actions* → *Android APK* → *Run workflow*.
- `.github/workflows/backend-tests.yml` : exécute la suite PHPUnit sur un squelette Laravel 12 neuf.
- L'URL de l'API se règle dans l'app (écran de connexion → serveur), l'APK autorise le HTTP en clair pour les tests sur réseau local.
