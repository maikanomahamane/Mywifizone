<?php

return [
    // Un routeur sans signal depuis plus longtemps que ça passe OFFLINE (l'agent envoie un signal toutes les ~30 s).
    'offline_after_seconds' => (int) env('NETWORK_OFFLINE_AFTER_SECONDS', 180),

    // Une commande livrée à l'agent doit recevoir son résultat dans ce délai, sinon elle est remise en file.
    'lease_seconds' => (int) env('NETWORK_LEASE_SECONDS', 60),

    // Nombre maximal de commandes remises à l'agent à chaque interrogation.
    'poll_batch' => (int) env('NETWORK_POLL_BATCH', 10),

    // Livraisons maximales par commande avant échec définitif, et délais (secondes) entre deux tentatives.
    'command_max_attempts' => (int) env('NETWORK_COMMAND_MAX_ATTEMPTS', 3),
    'retry_backoff_seconds' => [15, 60, 300],

    // Une commande restée en file plus longtemps (routeur hors ligne) échoue : le propriétaire relance via /routers/{id}/sync.
    'command_ttl_minutes' => (int) env('NETWORK_COMMAND_TTL_MINUTES', 1440),

    // Validité d'un code d'enrôlement (usage unique).
    'enrollment_ttl_minutes' => (int) env('NETWORK_ENROLLMENT_TTL_MINUTES', 1440),

    // Intervalles conseillés à l'agent.
    'poll_interval_seconds' => 10,
    'heartbeat_interval_seconds' => 30,

    // Vouchers par commande CREATE_VOUCHER, et taille de lot par défaut quand le plan ne la limite pas (clé max_batch_size).
    'voucher_chunk' => 50,
    'default_max_batch_size' => (int) env('NETWORK_DEFAULT_MAX_BATCH_SIZE', 500),

    // Connexion directe (API REST RouterOS, HTTPS) : le serveur appelle le routeur. Délais courts : un routeur lent ne
    // doit pas bloquer les files de travail.
    'rest' => [
        'timeout_seconds' => (int) env('ROUTER_REST_TIMEOUT', 10),
        'connect_timeout_seconds' => (int) env('ROUTER_REST_CONNECT_TIMEOUT', 5),
        // Vrai uniquement si le serveur joint les routeurs par un réseau privé (VPN WireGuard…) ET que les clients sont de
        // confiance : sinon un client pourrait viser les services internes de l'hébergement (SSRF).
        'allow_private' => (bool) env('ROUTER_REST_ALLOW_PRIVATE', false),
        // Une interrogation (santé + sessions) par routeur toutes les N secondes au plus.
        'heartbeat_seconds' => (int) env('ROUTER_REST_HEARTBEAT', 30),
        'dedicated_user' => 'wifizone',
        'dedicated_group' => 'wifizone',
        'group_policy' => 'read,write,api,rest-api,test',
        'forbidden_ports' => [22, 23, 25, 110, 143, 465, 587, 993, 995, 3306, 5432, 6379, 11211, 27017],
    ],
];
