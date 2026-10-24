# ADR 0005 — Connexion aux routeurs par agent sortant (Phase 3)

**Statut** : accepté (à valider sur matériel réel)

## Contexte
Les routeurs sont souvent derrière le CGNAT d'un opérateur mobile (Niger) : le cloud ne peut pas les joindre directement. Le cahier des charges prévoit un connecteur « API RouterOS / agent / VPN ».

## Décision
- Mode **AGENT** implémenté : un agent installé sur le site appelle le cloud (poll + heartbeat) et pilote le routeur par l'API REST RouterOS en local.
- Le mode `REST_API` direct reste une interface (`NetworkConnector`) avec un connecteur **indisponible** (`connector_unavailable`) : aucune supposition sur une exposition publique du routeur.
- **File de commandes** persistante (UUID, tenant, routeur, type, payload, statut, tentatives, résultat, erreur) : bail de 60 s, 3 tentatives, reprises à 15 s / 60 s / 300 s, expiration à 24 h, ordre de création respecté (profil avant vouchers).
- **Enrôlement** : code à usage unique (100 bits, haché, 24 h) → jeton `wza_…` (haché en base, limité à SON routeur, rotatable).
- **Anti-injection** : liste blanche côté cloud (codes, débits, noms de profils dérivés de l'UUID) ET revalidation côté agent ; valeurs transmises en JSON, jamais en script.

## Écarts assumés par rapport au cahier des charges
- Un **seul modèle de profil** (`hotspot_profiles.kind` = STANDARD/VIP) au lieu de deux tables.
- Un voucher est **lié à un routeur** (code unique par routeur) ; durée = temps de connexion cumulé (`limit-uptime`) ; état USED déduit des codes « épuisés » remontés par l'agent.
- Supervision des points d'accès (statut réel) : Phase 8.

## Conséquences
- Latence des actions = intervalle de poll (≈ 10 s) : les actions sont asynchrones (HTTP 202).
- Un agent compromis ne peut agir que sur son routeur ; un cloud compromis ne peut pas faire exécuter de script arbitraire.
