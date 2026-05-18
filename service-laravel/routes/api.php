<?php

declare(strict_types=1);

use App\Http\Controllers\HealthController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\SubscriberController;
use Illuminate\Support\Facades\Route;

// ── Health probes (no /api prefix) ──────────────────────────────────────────
Route::get('/health', [HealthController::class, 'liveness']);
Route::get('/ready',  [HealthController::class, 'readiness']);

// ── REST API ─────────────────────────────────────────────────────────────────
Route::prefix('api/v1')->group(function (): void {
    Route::post(
        'notifications/bulk',
        [NotificationController::class, 'bulkSend']
    );

    Route::get(
        'subscribers/{subscriberId}/notifications',
        [SubscriberController::class, 'index']
    );
});
