<?php

return [
    'default' => 'pgsql',

    'connections' => [
        'pgsql' => [
            'driver'         => 'pgsql',
            'host'           => env('DB_HOST', '127.0.0.1'),
            'port'           => env('DB_PORT', '5432'),
            'database'       => env('DB_DATABASE', 'notifications'),
            'username'       => env('DB_USERNAME', 'postgres'),
            'password'       => env('DB_PASSWORD', ''),
            'charset'        => 'utf8',
            'prefix'         => '',
            'prefix_indexes' => true,
            'search_path'    => 'public',
            'sslmode'        => 'prefer',
        ],

        // Lightweight in-memory driver used during Docker build / package:discover
        'sqlite' => [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ],
    ],

    'migrations' => [
        'table'                  => 'migrations',
        'update_date_on_publish' => true,
    ],
];
