<?php

return [
    // Combien de temps le code livré reste lisible avec le jeton de commande (le client doit pouvoir le retrouver, pas plus).
    'code_reveal_hours' => (int) env('PORTAL_CODE_REVEAL_HOURS', 24),

    // Plafond de commandes en attente par tenant : évite qu'un robot remplisse la table via le portail public.
    'max_pending_orders' => (int) env('PORTAL_MAX_PENDING_ORDERS', 500),

    // Une commande non payée au-delà de ce délai passe EXPIRED (les paiements en attente sont expirés par payments:expire-stale).
    'order_ttl_minutes' => (int) env('PORTAL_ORDER_TTL_MINUTES', 60),
];
