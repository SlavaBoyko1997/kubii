<?php

return [
    'catalog_profiling' => false,

    'large_catalog_threshold' => (int) env('LARGE_CATALOG_THRESHOLD', 2000),

    'prevent_lazy_loading' => env(
        'PREVENT_LAZY_LOADING',
        env('APP_ENV') !== 'production',
    ),

    'slow_query_log' => [
        'enabled' => env('SLOW_QUERY_LOG_ENABLED', true),
        'threshold_ms' => (float) env('SLOW_QUERY_THRESHOLD_MS', 50),
    ],
];
