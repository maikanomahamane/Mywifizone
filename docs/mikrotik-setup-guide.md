# Guide d'installation MikroTik

> **Statut** : procédures écrites d'après la documentation MikroTik (RouterOS 7, API REST) et **non testées sur un routeur réel**. À valider sur un routeur de test.

Deux façons de relier un routeur à MY WIFIZONE :

| Mode | Quand l'utiliser |
|---|---|
| **Identifiants MikroTik** (connexion directe, recommandé) | Le routeur est joignable depuis Internet : IP publique, nom de domaine (DDNS) ou VPN. Rien à installer sur le site. |
| **Agent** (section « Mode agent » plus bas) | Le routeur n'a pas d'IP publique (CGNAT, 4G…) : un petit programme sur le site se connecte vers le cloud. |

## 0. Mode « Identifiants MikroTik » (connexion directe)

**Sur le routeur (RouterOS 7.1+)**
1. Configurer le HotSpot : `/ip hotspot setup`.
2. Activer l'API REST en HTTPS :
   ```
   /certificate add name=wz-cert common-name=router.local days-valid=3650 key-usage=tls-server
   /certificate sign wz-cert
   /ip service set www-ssl certificate=wz-cert disabled=no
   /ip service set www disabled=yes
   ```
3. Autoriser le serveur MY WIFIZONE à joindre le port 443 (pare-feu), **et lui seul** si possible :
   ```
   /ip firewall filter add chain=input protocol=tcp dst-port=443 src-address=<IP-du-serveur-WIFIZONE> action=accept place-before=0
   /ip firewall filter add chain=input protocol=tcp dst-port=443 action=drop place-before=1
   ```

**Dans l'application** : *Réseau → Ajouter un routeur → Identifiants MikroTik* : adresse (IP publique ou domaine), port (443), identifiant **admin** et mot de passe.

Ce qui se passe :
- Les identifiants admin servent **une seule fois**. L'application crée sur le routeur un groupe et un utilisateur **`wifizone`** (droits `read,write,api,rest-api,test`, mot de passe aléatoire de 32 caractères), vérifie qu'ils fonctionnent, ne conserve **que ce compte** (chiffré) et **oublie l'admin**.
- Si le mot de passe admin change plus tard, rien ne casse : le compte `wifizone` continue de fonctionner. Pour recréer ce compte, utiliser *Reconnecter* dans le menu du routeur.
- Certificat auto-signé (cas courant) : laisser « Vérifier le certificat HTTPS » désactivé.
- Le serveur refuse les adresses locales (192.168.x.x, 10.x.x.x, 127.x…) : il doit joindre le routeur par son adresse **publique** (ou par VPN, voir `ROUTER_REST_ALLOW_PRIVATE` dans `docs/deployment-guide.md`).

Erreurs courantes : *identifiant ou mot de passe refusé* (mauvais compte), *routeur injoignable* (adresse, port 443, pare-feu, service `www-ssl`), *certificat refusé* (activer l'option auto-signé), *aucun HotSpot* (lancer `/ip hotspot setup`).

---

## Mode agent

## 1. Préparer le routeur (RouterOS 7.1+)

1. Mettre à jour RouterOS ; configurer le HotSpot (`/ip hotspot setup`) sur l'interface WiFi/bridge des clients. Noter le nom du serveur HotSpot (ex. `hotspot1`).
2. Créer un groupe et un compte **dédiés à l'agent** (pas `admin`) :
   ```
   /user group add name=wifizone policy=read,write,api,rest-api,test
   /user add name=wifizone group=wifizone password=<mot-de-passe-long> address=<IP-de-la-machine-agent>/32
   ```
   `address=` limite la connexion à la machine de l'agent.
3. Activer l'API REST (service `www-ssl`, avec un certificat) :
   ```
   /certificate add name=wz-cert common-name=router.local days-valid=3650 key-usage=tls-server
   /certificate sign wz-cert
   /ip service set www-ssl certificate=wz-cert disabled=no address=<IP-de-la-machine-agent>/32
   /ip service set www disabled=yes
   ```
4. Ne **jamais** exposer l'API REST sur Internet : seule la machine de l'agent doit pouvoir l'atteindre.

## 2. Enregistrer le routeur dans l'application

*Réseau → Ajouter un routeur* : choisir le site et nommer le routeur. L'application affiche **une seule fois** un code `WZ-XXXXX-XXXXX-XXXXX-XXXXX` (valable 24 h, usage unique).

## 3. Installer l'agent

Sur une machine du site, branchée au routeur (Node.js ≥ 20 ou Docker) — voir `connector-agent/README.md`.
Au premier lancement, l'agent échange le code contre un jeton et le stocke ; retirez ensuite `WZ_ENROLL_CODE`.
Le routeur passe **En ligne** dans l'application dès le premier signal de vie.

## 4. Vérifier

- *Réseau → Routeurs* : statut En ligne, version RouterOS, charge CPU.
- Créer un profil, générer 1 voucher, se connecter au WiFi avec le code (identifiant = mot de passe = code) : le voucher passe **Actif**, la session apparaît.

## Dépannage

| Symptôme | Cause probable |
|---|---|
| `invalid_enrollment_code` | Code expiré (24 h), déjà utilisé, ou mal copié → « Régénérer le jeton » |
| Routeur « Hors ligne » | Pas de signal depuis 3 min : agent arrêté, coupure Internet du site |
| Vouchers restés « En attente » | Agent hors ligne ; ils sont renvoyés via *Synchroniser* |
| Erreurs 401/403 RouterOS dans le journal de l'agent | Droits du compte `wifizone` ou `address=` incorrects |
