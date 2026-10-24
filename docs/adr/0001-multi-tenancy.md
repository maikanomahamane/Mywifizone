# ADR 0001 — Multi-tenancy : base partagée + `tenant_id`

**Statut** : proposé (Phase 1)

**Décision** : une seule base PostgreSQL, `tenant_id` (UUID) sur toute table métier. Le trait `BelongsToTenant` applique un scope global **fail-closed** (sans contexte tenant => aucun résultat), pose `tenant_id` à la création et le rend immuable. Le tenant provient uniquement de l'utilisateur authentifié (middleware `tenant`).

**Pourquoi** : simple à opérer pour un petit volume de tenants, migrations uniques, bon compromis coût/sécurité.

**Conséquences / limites**
- `User` n'est pas scopé (login avant contexte) : tout listing d'utilisateurs filtre explicitement par `tenant_id`.
- Les requêtes SQL brutes et jobs en file doivent poser le contexte eux-mêmes (à traiter en Phase 3).
- Durcissement prévu en Phase 8 : Row-Level Security PostgreSQL en seconde barrière.
