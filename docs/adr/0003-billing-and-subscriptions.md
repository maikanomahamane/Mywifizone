# ADR 0003 — Abonnements et facturation

**Statut** : proposé (Phase 2)

**Décisions**
- **Une ligne `subscriptions` par tenant** est la source unique de vérité (statut, fin d'essai, période, grâce). L'historique est porté par les factures et le journal d'audit. Aucune date d'essai n'est dupliquée dans `tenants`.
- **Essai de 14 jours** (confirmé), porté par la formule (`trial_days`, défaut = configuration) et prolongeable par une promotion `TRIAL_EXTENSION`.
- **Montants en entiers** (unités mineures) + code devise ; prix, limites et fonctionnalités des formules vivent en base et sont modifiables par le Super Admin. Un changement ne touche que les futures factures.
- **Cycle de vie** piloté par `subscriptions:process` (quotidien) : essai échu → `EXPIRED` ; période échue → `PAST_DUE` avec grâce (3 jours par défaut) → `EXPIRED` ; résiliation demandée → `CANCELLED` à l'échéance.
- **Abonnement inactif** : les écritures métier sont refusées (`402`), les lectures et le paiement restent possibles pour permettre de reprendre l'abonnement.
- **Facture avant paiement** : `OPEN` → `PAID` (reçu émis) ou `VOID`. L'abonnement n'est activé que lorsque la facture est payée, jamais à l'initiation.
- **Suspension** par le Super Admin : bloque connexion et jetons ; un paiement ne lève pas la suspension. À la réactivation, le statut est recalculé d'après les dates.
- **Limites du plan** appliquées côté serveur (`PlanEntitlements`), avec verrou sur la ligne d'abonnement pour qu'aucune création simultanée ne dépasse la limite.

**Limites connues** : numérotation de factures non séquentielle ; pas de prorata ; quota des codes de remise décompté au paiement (dépassement possible en cas de course).
