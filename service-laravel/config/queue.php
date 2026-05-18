<?php

// Parse RABBITMQ_URL → individual connection params understood by the driver.
$rmq = parse_url((string) env('RABBITMQ_URL', 'amqp://guest:guest@localhost:5672/'));

return [
    'default' => env('QUEUE_CONNECTION', 'rabbitmq'),

    'connections' => [
        'sync' => ['driver' => 'sync'],

        'rabbitmq' => [
            'driver' => 'rabbitmq',

            /*
             * Set to "false" so the worker uses blocking consume (lower CPU).
             * Requires the pcntl extension (installed in Dockerfile).
             */
            'worker' => env('RABBITMQ_WORKER', 'default'),

            'hosts' => [
                [
                    'host'     => $rmq['host']                        ?? 'localhost',
                    'port'     => $rmq['port']                        ?? 5672,
                    'user'     => $rmq['user']                        ?? 'guest',
                    'password' => $rmq['pass']                        ?? 'guest',
                    'vhost'    => ltrim($rmq['path'] ?? '/', '/') ?: '/',
                ],
            ],

            'options' => [
                'ssl_options' => [
                    'cafile'      => env('RABBITMQ_SSL_CAFILE'),
                    'local_cert'  => env('RABBITMQ_SSL_LOCALCERT'),
                    'local_key'   => env('RABBITMQ_SSL_LOCALKEY'),
                    'verify_peer' => env('RABBITMQ_SSL_VERIFY_PEER', true),
                    'passphrase'  => env('RABBITMQ_SSL_PASSPHRASE'),
                ],
                'queue' => [
                    // Main queue options — topology is fully managed by
                    // RabbitMQTopologyProvider; the driver only needs the name.
                    'exchange'                  => '',
                    'exchange_type'             => 'direct',
                    'exchange_routing_key'      => null,
                    'prioritize_delayed'        => false,
                    'queue_max_priority'        => null,
                    'exchange_declare'          => false,
                    'queue_declare'             => false,
                    'queue_durable'             => true,
                    'queue_exclusive'           => false,
                    'queue_auto_delete'         => false,
                    'heartbeat'                 => 60,
                    'connection_timeout'        => 5.0,
                    'read_write_timeout'        => 30.0,
                    'keepalive'                 => false,
                ],
            ],
        ],
    ],

    'batching' => [
        'database' => env('DB_CONNECTION', 'pgsql'),
        'table'    => 'job_batches',
    ],

    'failed' => [
        'driver'   => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'pgsql'),
        'table'    => 'failed_jobs',
    ],
];
