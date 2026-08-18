<?php

return [
    'order_polling' => [
        'enabled' => filter_var(env('ADMIN_ORDER_POLL_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'visible_seconds' => min(600, max(30, (int) env('ADMIN_ORDER_POLL_VISIBLE_SECONDS', 60))),
        'hidden_seconds' => max(
            min(1800, max(60, (int) env('ADMIN_ORDER_POLL_HIDDEN_SECONDS', 120))),
            min(600, max(30, (int) env('ADMIN_ORDER_POLL_VISIBLE_SECONDS', 60))),
        ),
    ],
];
