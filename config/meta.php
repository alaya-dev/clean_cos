<?php

return [
    'graph_api_version' => env('META_GRAPH_API_VERSION', 'v25.0'),
    'test_event_source_url' => env('META_TEST_EVENT_SOURCE_URL', env('APP_URL', 'http://localhost')),
    /*
    | The consent policy version is intentionally server-controlled. Bumping it
    | invalidates previously stored marketing decisions without replaying events.
    */
    'consent_policy_version' => 1,
    'consent_cookie' => 'pc_marketing_consent',
    'consent_lifetime_days' => 365,
];
