<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        // Use a custom callback so we can register routes WITHOUT the default
        // "/api" prefix that `api:` adds.  All routes (health probes + REST API)
        // are served under exactly the paths defined in routes/api.php.
        using: static function (): void {
            \Illuminate\Support\Facades\Route::middleware('api')
                ->group(base_path('routes/api.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // No session / web middleware needed for a pure API service
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // This is a pure JSON API service — always respond in JSON regardless
        // of the Accept header sent by the client.
        $exceptions->shouldRenderJsonWhen(fn ($request, $e) => true);
    })
    ->create();
