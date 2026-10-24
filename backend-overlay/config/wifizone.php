<?php

return [
    // Durée de vie des jetons (rotation du refresh token à chaque usage)
    'access_ttl_minutes' => (int) env('WIFIZONE_ACCESS_TTL_MINUTES', 15),
    'refresh_ttl_days' => (int) env('WIFIZONE_REFRESH_TTL_DAYS', 30),

    // Essai gratuit : 14 jours (décision confirmée). Surchargeable par plan (subscription_plans.trial_days)
    // depuis le Super Admin.
    'trial_days' => (int) env('WIFIZONE_TRIAL_DAYS', 14),

    // Période de grâce après échéance (jours). Surchargeable par plan (subscription_plans.grace_days).
    'grace_days' => (int) env('WIFIZONE_GRACE_DAYS', 3),

    // Formule choisie par défaut à l'inscription (l'essai porte sur ses limites).
    'default_plan' => env('WIFIZONE_DEFAULT_PLAN', 'STARTER'),

    'locales' => ['fr', 'en', 'ar', 'ha'],

    'sales' => [
        // Vrai : un encaissement en espèces exige une session de caisse ouverte (dans la devise de la vente).
        'require_cash_session_for_cash' => (bool) env('WIFIZONE_REQUIRE_CASH_SESSION', false),
    ],
];
