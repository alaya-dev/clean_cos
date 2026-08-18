<?php

return [
    'pruning_enabled' => (bool) env('OPERATIONS_PRUNING_ENABLED', false),
    'retention' => [
        'meta_success_days' => (int) env('OPERATIONS_META_SUCCESS_RETENTION_DAYS', 30),
        'meta_terminal_days' => (int) env('OPERATIONS_META_TERMINAL_RETENTION_DAYS', 90),
        'meta_purchase_attempt_days' => (int) env('OPERATIONS_META_PURCHASE_ATTEMPT_RETENTION_DAYS', 365),
        'failed_jobs_days' => (int) env('OPERATIONS_FAILED_JOBS_RETENTION_DAYS', 45),
        'temporary_upload_hours' => (int) env('OPERATIONS_TEMP_UPLOAD_RETENTION_HOURS', 48),
        // Applied by the monthly audit-only retention command. Audit rows stay
        // append-only during normal application usage.
        'audit_log_days' => (int) env('OPERATIONS_AUDIT_LOG_RETENTION_DAYS', 730),
    ],
    'health' => [
        'scheduler_max_age_minutes' => (int) env('OPERATIONS_SCHEDULER_MAX_AGE_MINUTES', 5),
        'queue_max_age_minutes' => (int) env('OPERATIONS_QUEUE_MAX_AGE_MINUTES', 5),
        'startup_grace_minutes' => (int) env('OPERATIONS_STARTUP_GRACE_MINUTES', 10),
        'pruning_max_age_hours' => (int) env('OPERATIONS_PRUNING_MAX_AGE_HOURS', 30),
        'failed_jobs_warning_count' => (int) env('OPERATIONS_FAILED_JOBS_WARNING_COUNT', 10),
        'disk_warning_percent' => (int) env('OPERATIONS_DISK_WARNING_PERCENT', 70),
        'disk_elevated_percent' => (int) env('OPERATIONS_DISK_ELEVATED_PERCENT', 80),
        'disk_critical_percent' => (int) env('OPERATIONS_DISK_CRITICAL_PERCENT', 90),
        'release_path' => env('OPERATIONS_RELEASE_PATH'),
        'backup_path' => env('OPERATIONS_BACKUP_PATH'),
    ],
    'temporary_upload_directories' => ['product-staging'],
    'queue_heartbeat_queue' => env('OPERATIONS_QUEUE_HEARTBEAT_QUEUE', 'critical'),
];
