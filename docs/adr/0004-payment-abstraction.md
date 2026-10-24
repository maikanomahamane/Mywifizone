# ADR 0004 — Abstraction des paiements

**Statut** : proposé (Phase 2)

**Décisions**
- **`PaymentProviderInterface`** (`code`, `isConfigured`, `initiate`, `parseWebhook`) : un fournisseur = une classe enregistrée dans `PaymentManager`. Le noyau (`PaymentService`) ne connaît aucun fournisseur en particulier.
- **Aucune API inventée** : Wave, Nita, Airtel Money et carte sont des `UnconfiguredProvider` qui refusent d'opérer. Le fournisseur `mock` est explicitement fictif et désactivé par défaut en production.
- **Webhooks** : endpoint public, signature vérifiée sur le **corps brut**, journal `webhook_events` unique sur `(fournisseur, event_id)` (insertion hors transaction pour PostgreSQL), traitement sous verrou dans une transaction SQL, dans le contexte du tenant propriétaire de la transaction.
- **Un succès n'active rien** si le montant ou la devise diffère de la transaction.
- **Machine à états** explicite (`PaymentStatus`) ; `EXPIRED → SUCCEEDED` autorisé (paiement tardif honoré), `FAILED`, `CANCELLED`, `REFUNDED` terminaux.
- **Appel fournisseur hors transaction SQL** : la transaction est créée `INITIATED`, puis `PENDING` avec la référence obtenue ; un webhook en avance sur cet enregistrement reste traitable.
- **Idempotence client** par `Idempotency-Key` (unique par tenant), avec détection de réutilisation pour une autre demande.
- **Secrets** uniquement dans l'environnement du serveur ; aucune donnée de carte stockée.

**À construire avec une API réelle** : réconciliation active (interroger le statut avant d'expirer), remboursements, retry d'initiation.
