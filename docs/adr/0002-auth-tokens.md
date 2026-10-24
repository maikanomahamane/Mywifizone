# ADR 0002 — Authentification : access token Sanctum + refresh token rotatif

**Statut** : proposé (Phase 1)

**Décision** : access token Sanctum de courte durée (15 min, expiration portée par le jeton) + refresh token opaque (30 jours) stocké haché (sha256) et **rotatif** : chaque refresh consomme l'ancien jeton et révoque l'access token associé. Le rejeu d'un refresh token déjà consommé révoque toutes les sessions de l'utilisateur et est audité.

**Pourquoi** : limite l'impact d'un vol de jeton sans exiger OAuth complet ; compatible mobile (secure storage) et web.

**Hors périmètre Phase 1** : reset password, vérification email/téléphone (stratégie SMS à décider), MFA, liste des appareils connus (les colonnes `device_name`/`ip` sont déjà enregistrées).
