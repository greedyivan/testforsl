<?php

// Parse REDIS_URL → individual params understood by Predis.
$redis = parse_url((string) env('REDIS_URL', 'redis://127.0.0.1:6379/0'));

return [
    'default' => env('CACHE_DRIVER', 'redis'),

    'stores' => [
        'array' => [
            'driver'    => 'array',
            'serialize' => false,
        ],

        'redis' => [
            'driver'     => 'redis',
            'connection' => 'cache',
            'lock_connection' => 'default',
        ],
    ],

    'prefix' => 'ns_cache',
];
