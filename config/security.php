<?php

return [
    /* Report-only is intentional during local/staging CSP host discovery. */
    'csp_mode' => env('SECURITY_CSP_MODE', 'report-only'),
    'content_security_policy' => env('SECURITY_CSP', "default-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'; img-src 'self' data: blob:; font-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; connect-src 'self';"),
    'hsts_enabled' => filter_var(env('SECURITY_HSTS_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    'admin_absolute_session_minutes' => (int) env('ADMIN_ABSOLUTE_SESSION_MINUTES', 480),
];
