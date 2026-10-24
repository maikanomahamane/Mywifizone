# Guide — brancher un fournisseur de paiement

**Règle d'or** : aucune implémentation sans la **documentation officielle** du fournisseur, un **contrat/compte marchand** et des **identifiants de test (sandbox)**. On n'invente ni endpoint, ni format de webhook, ni méthode de signature. Tant que ces éléments manquent, l'adaptateur reste un `UnconfiguredProvider` : il répond « non branché » et n'est jamais proposé aux clients.

## État actuel

| Code | Classe | État |
|---|---|---|
| `mock` | `MockPaymentProvider` | Branché — **fictif**, dev/tests uniquement (`PAYMENT_MOCK_ENABLED`, désactivé par défaut en production, inutilisable sans secret) |
| `wave`, `nita`, `airtel`, `card` | `WaveAdapter`, `NitaAdapter`, `AirtelMoneyAdapter`, `CardAdapter` | **Non branchés** (héritent de `UnconfiguredProvider`) |

Un fournisseur n'est proposé que s'il est **activé** par le Super Admin (`PUT /admin/payment-providers/{code}`) **et** branché (`isConfigured()`) **et** compatible avec la devise de la facture.

## Prérequis à rassembler

1. Documentation officielle de l'API (initiation du paiement, statuts, webhooks, signature, remboursements).
2. Identifiants sandbox, puis production, stockés **uniquement** dans l'environnement du serveur (jamais dans Git ni dans l'application mobile).
3. Pays et devises couverts par le contrat (à saisir côté Super Admin : `countries`, `currencies`).
4. Délai de validité d'une demande de paiement (ajuster `PAYMENT_PENDING_TIMEOUT_MINUTES`).

## Étapes

1. **Variables d'environnement** : remplacer `requiredEnv()` (provisoire) par celles de la documentation ; les lire dans `config/payments.php` ; les documenter dans `.env.example`.
2. **Adaptateur** : faire implémenter `PaymentProviderInterface` à la classe (en remplacement de `UnconfiguredProvider`) :
   - `isConfigured()` : vrai uniquement si **tous** les identifiants sont présents ;
   - `initiate()` : appelle l'API du fournisseur, renvoie `PaymentInitiation(referenceFournisseur, instructions)` ; lève une exception en cas d'échec (la transaction passe à `FAILED`, le client reçoit `503 provider_unavailable`) ;
   - `parseWebhook()` : **vérifie la signature sur le corps brut**, l'horodatage si le fournisseur en fournit un, puis renvoie un `WebhookEvent` normalisé (`eventId`, `providerReference`, `status`, `amount`). Lève `InvalidWebhookException` si la signature est absente ou fausse.
3. **Enregistrement** : instancier l'adaptateur dans `AppServiceProvider` (ligne du `PaymentManager`) avec ses identifiants.
4. **Fournisseur en base** : le seeder crée la ligne `payment_providers` (désactivée). Le Super Admin l'active et renseigne pays/devises.
5. **Tests** (modèle : `CheckoutAndWebhookTest`) : signature valide/fausse, horodatage périmé, rejeu du même `event_id`, montant ou devise différents, succès après échec, échec d'initiation.
6. **Recette sandbox** avec de vrais appels avant toute activation en production. Ne jamais déclarer l'intégration fonctionnelle sans ce test réel.

## Ce que le noyau garantit déjà (ne pas le réimplémenter)

- **Idempotence** : `webhook_events` est unique sur `(fournisseur, event_id)` ; un rejeu répond `duplicate`. Une facture déjà payée ne prolonge jamais l'abonnement deux fois (alerte d'audit `payment.duplicate_success`).
- **Montant et devise** vérifiés contre la transaction : en cas d'écart, rien n'est activé (`payment.amount_mismatch`).
- **Machine à états** : `INITIATED → PENDING → SUCCEEDED | FAILED | EXPIRED | CANCELLED` ; un succès tardif après `EXPIRED` est honoré (le fournisseur fait foi), un succès après `FAILED` est ignoré.
- **Idempotence côté client** : en-tête `Idempotency-Key` sur `POST /subscription/checkout`.
- **Audit** de chaque étape ; **aucune donnée de carte** n'est stockée (utiliser le paiement hébergé/tokenisé du prestataire pour les cartes).

## Réconciliation (à construire avec l'API réelle)

`payments:expire-stale` clôture les transactions restées en attente trop longtemps, mais **n'interroge pas** le fournisseur. Avec une API officielle, ajouter une méthode « statut d'une transaction » à l'adaptateur et l'appeler avant d'expirer, pour rattraper un webhook perdu.

## À ne pas faire

- Copier un endpoint ou une signature depuis un exemple trouvé en ligne.
- Activer un fournisseur en production sans test sandbox complet.
- Journaliser des secrets, des numéros complets ou des corps de webhook non filtrés.
