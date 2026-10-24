# ADR 0008 — International : langues, RTL, devises (Phase 6)

Statut : accepté — Flutter et PHPUnit non exécutés localement.

## Décisions

1. **Langues** fr/en/ar/ha : déjà natives (ARB à parité de clés, RTL géré par Flutter via la locale `ar`). Aucune nouvelle langue ajoutée ; les textes ar/ha restent à faire relire.
2. **Devises** : table `currencies` (8 initiales : XOF, XAF, NGN, GHS, KES, USD, EUR, GBP), extensible sans code. L'unité mineure vient de la base ; le mobile ne la devine plus (liste `GET /currencies`).
3. **Séparation des notions** (cahier des charges) : montant = entier en unités mineures + devise ; devise d'affichage par défaut = `tenants.currency` ; devise de règlement = celle de la vente/du paiement ; **taux = table à part, indicative**.
4. **Un taux ne modifie jamais un prix** : changer `tenants.currency` ou saisir un taux ne touche ni profils, ni ventes, ni caisse, ni rapports ; la conversion n'est qu'un endpoint de lecture (`indicative: true`).
5. **Taux** : saisis par le propriétaire (fonction `multi_currency`, PRO/BUSINESS), entiers `× 10^8`, sens unique. Parités fixes par traité codées en dur : 1 EUR = 655,957 XOF = 655,957 XAF, 1 XOF = 1 XAF (non modifiables). **Aucun fournisseur de taux externe n'est appelé** : pas d'API inventée.
6. **Arithmétique** : multiplication et arrondi (demi-unité supérieure) sur chaînes décimales, sans float ni bcmath ; montants ≤ 10^12 unités mineures, taux ≤ 9 999 999,99999999.
7. **Réglages du tenant** : `PUT /tenants/me` (propriétaire, abonnement actif) pour nom, langue, devise, pays, fuseau (liste PHP).

## Mobile (ajouts)

8. **Chiffres latins** dans toutes les langues, y compris l'arabe : montants, codes et reçus restent comparables.
9. **Saisie d'un prix en unités majeures** (« 10,5 » USD → 1050) via `parseMajorToMinor`, sans flottant ; trop de décimales refusées. Décimales d'affichage lues dans le catalogue (`CurrencyFormats`).
10. **Correctif** : les profils étaient créés en `XOF` en dur et affichaient les prix bruts ; ils utilisent la devise du tenant et `formatMoney`.
11. Écrans *Région et devise* et *Taux de change* (menu Plus, propriétaire). La langue est appliquée à la connexion et à l'enregistrement des réglages, mais **non mémorisée** entre deux lancements si elle est changée dans « Plus ».

## Limites

- Pas de conversion automatique des totaux de rapports (les totaux restent par devise).
- Le taux inverse n'est pas déduit : à saisir explicitement.
- Moyens de paiement par pays : la couche d'adaptateurs existe, aucun fournisseur réel n'est branché (documentation officielle et identifiants requis).
- XOF/XAF affichés « FCFA » ; pas de règles fiscales par pays.
