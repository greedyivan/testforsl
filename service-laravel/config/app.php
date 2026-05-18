<?php

use Illuminate\Support\Facades\Facade;
use Illuminate\Support\ServiceProvider;

return [
    'name'     => env('APP_NAME', 'Notification Service'),
    'env'      => env('APP_ENV', 'production'),
    'debug'    => (bool) env('APP_DEBUG', false),
    'url'      => env('APP_URL', 'http://localhost'),
    'timezone' => 'UTC',
    'locale'   => 'en',
    'key'      => env('APP_KEY'),
    'cipher'   => 'AES-256-CBC',

    /*
     * Include all default Laravel framework providers so that deferred bindings
     * (queue, db, cache, etc.) are resolvable from the IoC container.
     * Application providers are registered via bootstrap/providers.php.
     */
    'providers' => ServiceProvider::defaultProviders()->toArray(),

    'aliases' => Facade::defaultAliases()->toArray(),
];
