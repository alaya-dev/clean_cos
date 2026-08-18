<?php

return [
    'default_base_url' => env('NAVEX_API_BASE_URL', 'https://app.navex.tn'),
    'allowed_hosts' => array_filter(explode(',', (string) env('NAVEX_ALLOWED_HOSTS', 'app.navex.tn'))),
    'connect_timeout_seconds' => (int) env('NAVEX_CONNECT_TIMEOUT_SECONDS', 5),
    'timeout_seconds' => (int) env('NAVEX_TIMEOUT_SECONDS', 20),
    'sync_interval_minutes' => (int) env('NAVEX_SYNC_INTERVAL_MINUTES', 15),
    'sync_batch_size' => (int) env('NAVEX_SYNC_BATCH_SIZE', 50),
];
