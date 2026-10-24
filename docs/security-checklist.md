# Checklist sécurité — MY WIFIZONE

Légende : ✅ en place dans le code (testé par PHPUnit, non exécuté ici sauf mention) · 🔧 à faire/vérifier par l'exploitant · ❌ pas fait.

## Identité et accès
- ✅ Jetons d'accès courts (15 min) + refresh token à rotation ; seul le hash (sha256) est stocké ; rejouer un refresh révoque la famille.
- ✅ RBAC côté serveur (permissions par rôle, middleware `permission:`), politiques par ressource.
- ✅ Limitation de débit : connexion (par identifiant + par IP), webhooks, portail public, agent, documents, sondes.
- ❌ MFA, réinitialisation de mot de passe, vérification e-mail/téléphone (Phase 1, restent à faire).
- 🔧 Vérifier qu'aucun compte Super Admin n'utilise un mot de passe faible (`wifizone:create-super-admin`).

## Multi-tenant
- ✅ Scope global `BelongsToTenant` **fermé par défaut** (sans contexte, aucune ligne), tenant tiré de l'utilisateur authentifié, jamais d'un paramètre.
- ✅ Validation `Rules::ownedBy` : un identifiant d'un autre tenant répond comme un identifiant inexistant (pas de fuite d'existence).
- ✅ Tests d'isolation sur chaque module (réseau, ventes, caisse, documents, portail, taux).
- ✅ Sondes et métriques : agrégats uniquement, aucun identifiant de tenant/utilisateur/code.

## Argent et paiements
- ✅ Montants en entiers (unités mineures) + devise ; jamais de flottant ; un taux ne modifie jamais un prix.
- ✅ Webhooks : signature HMAC + horodatage borné, idempotence (événement unique), montant et devise vérifiés ; un montant différent n'active rien.
- ✅ Achat direct : le voucher n'est livré qu'après paiement confirmé ; échec/annulation/expiration ne livre rien ; paiement sans stock → `NEEDS_ATTENTION` + alerte critique.
- ✅ Fournisseur fictif refusé en production (même si `PAYMENT_MOCK_ENABLED=true`).
- ✅ Aucune donnée bancaire stockée ; aucun mot de passe Starlink demandé ni stocké.
- 🔧 Aucun fournisseur réel branché : à auditer (signature, IP, montants) lors de l'intégration.
- ❌ Reversement aux propriétaires non conçu.

## MikroTik et agent
- ✅ Connexion **sortante** de l'agent : aucun port exposé, aucun identifiant RouterOS stocké dans le cloud.
- ✅ Code d'enrôlement à usage unique (hash), jeton d'agent hashé et limité à SON routeur, rotation possible.
- 🔧 Créer sur le routeur un utilisateur API à permissions minimales (guide MikroTik) ; **non testé sur un vrai routeur**.

## Entrées, sorties, fichiers
- ✅ Validation de toutes les entrées ; erreurs sans stack trace en production ; enveloppe d'erreur unique.
- ✅ PDF : tout texte utilisateur échappé, couleur validée `#RRGGBB`, seule image = logo ré-encodé.
- ✅ Logo : PNG/JPEG seulement, décodé et ré-encodé (métadonnées et données ajoutées supprimées), nom aléatoire, disque privé.
- ✅ CSV : neutralisation de l'injection de formules.
- ✅ Portail : CSP stricte, texte inséré via `textContent`, aucun `innerHTML`, aucune ressource tierce ; le code n'est envoyé qu'à une adresse de réseau local.
- ✅ QR : ne contient aucun secret serveur.

## Transport et en-têtes
- ✅ HTTPS terminé par Caddy (certificat automatique) ; HSTS émis quand la requête est en HTTPS ; `TRUSTED_PROXIES` pour la vraie IP cliente (sinon la limitation par IP serait faussée).
- ✅ `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`, `Cache-Control: no-store` sur l'API.
- 🔧 **Mobile : le HTTP en clair est autorisé dans l'APK de test.** Pour tout build destiné à des utilisateurs : lancer le workflow avec `allow_cleartext=false` et une URL `https://`.
- ❌ Pas d'épinglage de certificat dans l'app.

## Secrets
- ✅ Aucun secret dans Git (`scripts/scan-secrets.sh`, workflow *Sécurité*) ni dans l'app mobile ; configuration par variables d'environnement.
- 🔧 `.env.production` créé sur le serveur, droits `600`, `APP_KEY` sauvegardée à part (la perdre rend illisibles les données chiffrées).
- 🔧 Changer tous les secrets de `.env.production.example` ; faire tourner `METRICS_TOKEN` et les mots de passe en cas de départ d'un administrateur.

## Journaux et audit
- ✅ Journaux JSON : `request_id`, `tenant_id`, route **modèle** (jamais l'URL réelle : jetons de commande, slug), masquage des clés sensibles.
- ✅ Journal d'audit métier (connexions, ventes, annulations, paiements, réglages, exports sans codes).
- ❌ Purge/rétention des `audit_logs` et `webhook_events` non automatisée : à décider selon la politique de conservation.
- ❌ Export/suppression des données personnelles (RGPD/loi nigérienne) : processus non conçu.

## Données et reprise
- ✅ Sauvegardes `pg_dump` + chiffrement optionnel + rotation ; script de restauration **et** test de restauration (exécutés ici sur PostgreSQL 16 avec un schéma de test, pas sur la vraie base).
- 🔧 Copier les sauvegardes **hors du serveur**, et lancer `restore-test.sh` régulièrement sur la vraie base.
- 🔧 Chiffrement au repos = chiffrement du disque du serveur (non géré ici).

## Dépendances et chaîne de livraison
- ✅ CI : tests SQLite **et PostgreSQL 16**, `composer audit`, analyse de secrets, test E2E sur la pile Docker, Dependabot.
- 🔧 Committer le `composer.lock` du squelette généré (il n'existe qu'en CI) pour figer les versions.
- ❌ Pas de test d'intrusion ni de revue externe.
