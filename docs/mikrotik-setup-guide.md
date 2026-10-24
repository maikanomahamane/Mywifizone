# Guide d'installation MikroTik + agent

> **Statut** : procédure écrite d'après la documentation MikroTik (RouterOS 7, REST API) et **non testée sur un routeur réel**. À valider sur un routeur de test.

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
