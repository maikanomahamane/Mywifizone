# Agent MY WIFIZONE (référence)

Petit programme Node.js (sans dépendance) qui s'installe **près du routeur MikroTik** (Raspberry Pi, mini-PC, serveur du site) et :

1. s'enrôle auprès du cloud avec un code à usage unique, puis conserve un jeton (`agent-state.json`, permissions 0600) ;
2. interroge le cloud toutes les 10 s (`/agent/poll`) et exécute les commandes (création/révocation de vouchers, profils, déconnexion…) via l'**API REST de RouterOS 7** ;
3. envoie toutes les 30 s un signal de vie avec la santé du routeur, les sessions actives et les vouchers épuisés.

Toutes les connexions sont **sortantes** : aucune IP publique ni redirection de port n'est nécessaire (fonctionne derrière le CGNAT).

> ⚠️ **Non validé sur un routeur réel.** Les tests (`npm test`, 9 tests) tournent contre un simulateur de l'API REST RouterOS écrit d'après la documentation MikroTik. Essayez d'abord sur un routeur de test.

## Configuration (variables d'environnement)

| Variable | Rôle |
|---|---|
| `WZ_API_URL` | URL de l'API, ex. `https://api.exemple.com/api/v1` |
| `WZ_ENROLL_CODE` | Code `WZ-XXXXX-…` affiché par l'application (premier lancement uniquement) |
| `WZ_STATE_FILE` | Fichier de jeton (défaut `./agent-state.json`) |
| `ROUTEROS_URL` | ex. `https://192.168.88.1` |
| `ROUTEROS_USER` / `ROUTEROS_PASSWORD` | Compte **dédié** de l'agent (voir le guide) |
| `ROUTEROS_INSECURE` | `1` pour accepter un certificat auto-signé du routeur (réseau local uniquement ; la connexion vers le cloud reste vérifiée) |

```bash
npm test                                   # simulateur RouterOS + faux cloud
WZ_API_URL=… WZ_ENROLL_CODE=WZ-… ROUTEROS_URL=… ROUTEROS_USER=wifizone ROUTEROS_PASSWORD=… node src/main.js
```

## Sécurité

- Le jeton ne vit que sur la machine de l'agent ; le révoquer = « Régénérer le jeton » dans l'application (l'ancien cesse de marcher immédiatement).
- L'agent **revalide** chaque commande (codes, noms de profils, débits, identifiants de session) par liste blanche et n'envoie les valeurs à RouterOS qu'en **corps JSON** : jamais de script ni de commande construite par concaténation. Un type de commande inconnu est refusé.
- Le compte RouterOS de l'agent doit avoir uniquement les droits `api`, `rest-api`, `read`, `write` sur le HotSpot (voir le guide).
