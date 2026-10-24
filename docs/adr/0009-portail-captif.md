# ADR 0009 — Portail captif, achat direct, services externes (Phase 7)

Statut : accepté — **non testé sur un routeur MikroTik réel ni avec un vrai fournisseur de paiement**.

## Décisions
1. **Portail public sans compte** : `GET /portal/{slug}/page` sert une page HTML responsive unique (fr/en/ar/ha, RTL, logo et couleur du propriétaire, connexion par code, offres, achat). Le tenant est déduit du **slug public** (16 caractères aléatoires, régénérable) ou du **jeton de commande** (32 caractères) : aucun paramètre libre ne désigne un tenant.
2. **Aucun secret dans la page** : pas de clé de paiement, ni d'identifiant interne. Les textes du propriétaire passent par un bloc JSON échappé puis `textContent` (jamais `innerHTML`) ; CSP stricte (`default-src 'none'`, pas de ressource tierce).
3. **Connexion** : la page poste `username=password=code` vers `link-login-only` fourni par le HotSpot. Pour qu'un lien forgé ne puisse pas envoyer un code ailleurs, seules les adresses locales (10/8, 172.16/12, 192.168/16, 100.64/10, 169.254/16, noms sans point ou `.lan/.local/.home/.internal`) sont acceptées. Logique testée en Node (`scripts/portal-js-test.js`). Modèle de page routeur : `docs/mikrotik-portal-login.html`.
4. **Achat direct découplé des vouchers** : choix du pass → `portal_orders` (PENDING_PAYMENT) + transaction de paiement → **webhook signé** → montant/devise vérifiés (déjà fait par `PaymentService`) → `PortalFulfillmentService` prend un voucher AVAILABLE du stock, l'enregistre comme vente sans vendeur (`MOBILE_MONEY`, référence = référence fournisseur) et l'affiche. Idempotent (rejeu de webhook, `client_ref`).
5. **Pas de stock réservé** par des paniers abandonnés. Conséquence assumée : si le dernier ticket est vendu entre la commande et le paiement, la commande passe **NEEDS_ATTENTION** (audit `portal.no_stock_after_payment`) : le propriétaire livre ou rembourse à la main.
6. **Code livré** : lisible avec le jeton pendant `PORTAL_CODE_REVEAL_HOURS` (24 h) puis masqué ; jamais dans les listes du propriétaire.
7. **Accès** : fonction `captive_portal` (PRO/BUSINESS) + abonnement actif + tenant actif, sinon `503 portal_unavailable`. Limites : 120 req/min/IP, 10 commandes/min/IP, 500 commandes en attente par tenant.
8. **Services externes (Starlink)** : simples liens https vers le portail officiel, sans identifiant intégré ; ni mot de passe stocké, ni scraping, ni API inventée (`external_links`, 20 max).

## Limites et risques
- **Aucun fournisseur de paiement réel** : seul le mock existe ; Wave/Nita/Airtel/carte restent non branchés.
- **Encaissement** : les fournisseurs sont configurés au niveau plateforme (abonnements SaaS). Reverser l'argent des ventes aux propriétaires (comptes marchands, commissions, réconciliation) n'est **pas conçu** : à décider avec le fournisseur retenu avant toute mise en production de l'achat direct.
- Walled garden RouterOS, HTTPS externe et variables de `login.html` : à valider sur routeur.
- Le téléphone du client est stocké (facultatif) pour le suivi ; à couvrir par la politique de confidentialité.
- Traductions ar/ha du portail à relire.
- Pas de session « activée après paiement » côté routeur autre que le voucher déjà synchronisé : si le voucher n'est pas SYNCED il n'est jamais vendu.
