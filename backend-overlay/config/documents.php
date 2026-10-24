<?php

return [
    // Garde-fous : un PDF ou un export est généré à la demande, donc borné.
    'max_tickets_per_pdf' => (int) env('WIFIZONE_MAX_TICKETS_PER_PDF', 500),
    'max_csv_rows' => (int) env('WIFIZONE_MAX_CSV_ROWS', 50000),
    'csv_max_days' => 366,

    // Largeurs d'imprimante thermique courantes (mm).
    'thermal_widths' => [58, 80],

    // Les logos sont stockés sur un disque PRIVÉ (jamais servi directement par le serveur web).
    'logo_disk' => env('WIFIZONE_LOGO_DISK', 'local'),

    // Dossier de travail de mPDF (polices en cache). Doit être inscriptible par PHP.
    'mpdf_temp_dir' => storage_path('app/mpdf'),
];
