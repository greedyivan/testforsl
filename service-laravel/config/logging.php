<?php

return [
    'default' => env('LOG_CHANNEL', 'stderr'),

    'channels' => [
        'stderr' => [
            'driver'    => 'monolog',
            'handler'   => Monolog\Handler\StreamHandler::class,
            'formatter' => Monolog\Formatter\LineFormatter::class,
            'with'      => [
                'stream' => 'php://stderr',
            ],
            'level' => env('LOG_LEVEL', 'info'),
        ],

        'null' => [
            'driver'  => 'monolog',
            'handler' => Monolog\Handler\NullHandler::class,
        ],
    ],
];
