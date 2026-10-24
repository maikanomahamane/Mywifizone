# Release notes — MY WIFIZONE V1 (candidate)

Statut : **candidate non validée**. Le code a été écrit phase par phase sans pouvoir exécuter PHPUnit, Flutter ni un vrai MikroTik. Voir « Validations restantes ».

## Contenu
| Phase | Livré |
|---|---|
| 1–2 | Inscription, connexion (jetons à rotation), multi-tenant, RBAC, essai 14 jours, formules STARTER/PRO/BUSINESS, factures, paiements (fournisseur fictif + adaptateurs non branchés), Super Admin |
| 3 | Sites, routeurs, agent sortant MikroTik (référence Node), profils, vouchers, lots, sessions |
| 4 | Ventes, caisse, rapports, tableau de bord, agents d'équipe |
| 5 | PDF (A4, A5, individuel, thermique), QR, reçus, factures, export CSV, personnalisation (logo, couleur, slogan), partage et impression mobile |
| 6 | fr/en/ar/ha, RTL, devises XOF XAF NGN GHS KES USD EUR GBP, taux indicatifs, réglages régionaux |
| 7 | Portail captif responsive multilingue, achat direct (paiement → webhook vérifié → voucher), liens externes (Starlink : lien officiel uniquement) |
| 8 | En-têtes de sécurité, journaux JSON, sondes, métriques, alertes, index, déploiement Docker/Caddy, sauvegardes + test de restauration, CI (SQLite + PostgreSQL, E2E, secrets), checklist sécurité |

## Validations restantes (dans cet ordre)
1. `make setup && make test` : première exécution réelle de PHPUnit (242 tests écrits, jamais lancés).
2. Workflow *Backend tests* (job PostgreSQL) puis *E2E* ; consulter l'artefact `sample-pdfs` (arabe, hausa, QR).
3. APK : workflow *Android APK* ; `flutter pub get` confirmera les versions des plugins.
4. Un vrai MikroTik : agent, création de tickets, page de connexion du portail (`docs/mikrotik-portal-login.html`), connexion automatique par QR.
5. Un vrai fournisseur de paiement (documentation officielle + identifiants) avant toute vente en ligne.
6. Relecture des traductions arabe et hausa par des locuteurs.

## Limites connues
- Pas de MFA, de réinitialisation de mot de passe, de vérification e-mail/téléphone.
- Pas de reversement aux propriétaires, pas de numérotation fiscale des factures.
- Pas de test de charge ni de test d'intrusion ; pas de suivi d'erreurs externe.
- La langue choisie dans « Plus » n'est pas mémorisée ; dates non localisées ; logo téléversé par l'API seulement.
