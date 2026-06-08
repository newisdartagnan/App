<?php

return [
    'establishment_code' => env('ESTABLISHMENT_CODE', 'HGR_KINSHASA_01'),
    'establishment_name' => env('ESTABLISHMENT_NAME', 'Établissement hospitalier'),
    'central_api_url' => env('CENTRAL_API_URL'),
    'central_sync_token' => env('CENTRAL_SYNC_TOKEN'),
    'offline_token_ttl_hours' => 48,
    'sync_batch_size' => 500,
    'sync_interval_minutes' => 15,
    'backup_retention_days' => 7,
];
