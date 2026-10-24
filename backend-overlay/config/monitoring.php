<?php

return [
    // Jeton (Bearer) pour GET /api/v1/internal/metrics. Vide = endpoint désactivé (404). Générer : openssl rand -hex 32
    'metrics_token' => (string) env('METRICS_TOKEN', ''),

    // Seuils de `php artisan monitor:check` (planifié toutes les 5 minutes). 0 désactive la règle « >= ».
    'thresholds' => [
        'needs_attention_max' => (int) env('ALERT_NEEDS_ATTENTION_MAX', 0),   // commandes payées sans ticket livré
        'stuck_payments_max' => (int) env('ALERT_STUCK_PAYMENTS_MAX', 0),     // paiements PENDING au-delà du délai
        'failed_jobs_max' => (int) env('ALERT_FAILED_JOBS_MAX', 0),
        'sync_failed_max' => (int) env('ALERT_SYNC_FAILED_MAX', 0),
        'queue_size_max' => (int) env('ALERT_QUEUE_SIZE_MAX', 500),
        'commands_pending_old_max' => (int) env('ALERT_COMMANDS_OLD_MAX', 0),
        'offline_ratio_max' => (float) env('ALERT_OFFLINE_RATIO_MAX', 0.5),
    ],

    // Une commande routeur en attente depuis plus longtemps que ça est « coincée » (agent muet).
    'command_old_minutes' => (int) env('MONITOR_COMMAND_OLD_MINUTES', 30),
];
