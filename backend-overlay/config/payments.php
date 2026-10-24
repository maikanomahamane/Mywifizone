<?php

return [
    // Fournisseur FICTIF (dev/tests). Désactivé par défaut en production, et inutilisable sans secret.
    'mock' => [
        'enabled' => (bool) env('PAYMENT_MOCK_ENABLED', env('APP_ENV') !== 'production'),
        'secret' => (string) env('PAYMENT_MOCK_WEBHOOK_SECRET', ''),
    ],

    // Tolérance d'horloge sur les webhooks signés avec horodatage (anti-rejeu).
    'webhook_tolerance_seconds' => (int) env('PAYMENT_WEBHOOK_TOLERANCE_SECONDS', 300),

    // Une transaction restée INITIATED/PENDING plus longtemps est clôturée par `payments:expire-stale`.
    // Hypothèse à ajuster avec le fournisseur réel (la validité d'une demande de paiement mobile varie).
    'pending_timeout_minutes' => (int) env('PAYMENT_PENDING_TIMEOUT_MINUTES', 60),
];
