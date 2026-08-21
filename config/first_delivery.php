<?php

return [
    'default_base_url' => 'https://www.firstdeliverygroup.com/api/v2',
    'allowed_hosts' => ['www.firstdeliverygroup.com'],
    'connect_timeout_seconds' => (int) env('FIRST_DELIVERY_CONNECT_TIMEOUT_SECONDS', 5),
    'timeout_seconds' => (int) env('FIRST_DELIVERY_TIMEOUT_SECONDS', 20),
    'sync_interval_minutes' => (int) env('FIRST_DELIVERY_SYNC_INTERVAL_MINUTES', 15),
    'sync_batch_size' => (int) env('FIRST_DELIVERY_SYNC_BATCH_SIZE', 20),
];
