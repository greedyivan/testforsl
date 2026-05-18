<?php

declare(strict_types=1);

namespace App\Providers;

use App\Repositories\NotificationRepository;
use App\Services\IdempotencyService;
use App\Services\NotificationPublisher;
use App\Services\NotificationService;
use App\Services\ProviderFactory;
use Illuminate\Support\ServiceProvider;
use Predis\Client as Redis;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->configureDatabaseFromUrl();
        $this->configureRedisFromUrl();
        $this->registerSingletons();
    }

    public function boot(): void {}

    /**
     * Нормализует DATABASE_URL из Python-формата (postgresql+asyncpg://)
     * в PDO-совместимый формат и перезаписывает конфиг database.pgsql.
     */
    private function configureDatabaseFromUrl(): void
    {
        $dbUrl = (string) env('DATABASE_URL', '');
        if ($dbUrl === '') {
            return;
        }

        $parsed = parse_url(
            str_replace(['postgresql+asyncpg://', 'postgres://'], 'pgsql://', $dbUrl)
        );

        if (! is_array($parsed)) {
            return;
        }

        config([
            'database.connections.pgsql.host'     => $parsed['host'] ?? '127.0.0.1',
            'database.connections.pgsql.port'     => $parsed['port'] ?? 5432,
            'database.connections.pgsql.database' => ltrim($parsed['path'] ?? '/notifications', '/'),
            'database.connections.pgsql.username' => $parsed['user'] ?? 'postgres',
            'database.connections.pgsql.password' => $parsed['pass'] ?? '',
        ]);
    }

    /**
     * Парсит REDIS_URL, настраивает конфиг Redis и регистрирует синглтон Predis\Client для DI.
     *
     * Конфигурация Redis намеренно задаётся здесь, а не в config/database.php,
     * потому что REDIS_URL — единый URL вместо отдельных host/port/database.
     * Laravel не умеет парсировать URL-формат для Redis из коробки.
     */
    private function configureRedisFromUrl(): void
    {
        $redisUrl    = (string) env('REDIS_URL', '');
        $parsed      = $redisUrl !== '' ? parse_url($redisUrl) : [];
        $host        = (string) ($parsed['host'] ?? '127.0.0.1');
        $port        = (int)    ($parsed['port'] ?? 6379);
        $database    = ltrim((string) ($parsed['path'] ?? '/0'), '/') ?: '0';

        config([
            'database.redis.client'  => 'predis',
            'database.redis.default' => ['url' => null, 'host' => $host, 'port' => $port, 'database' => $database],
            'database.redis.cache'   => ['url' => null, 'host' => $host, 'port' => $port, 'database' => $database],
        ]);

        $this->app->singleton(
            Redis::class,
            fn () => new Redis(['scheme' => 'tcp', 'host' => $host, 'port' => $port, 'database' => (int) $database]),
        );
    }

    /** Регистрирует все синглтоны приложения в IoC-контейнере. */
    private function registerSingletons(): void
    {
        $this->app->singleton(NotificationRepository::class);
        $this->app->singleton(NotificationPublisher::class);

        $this->app->singleton(ProviderFactory::class,
            fn ($app) => new ProviderFactory($app->make(Redis::class))
        );

        // TTL передаётся явно из конфига, чтобы IdempotencyService можно было
        // инстанцировать в unit-тестах без загрузки Laravel-приложения.
        $this->app->singleton(IdempotencyService::class,
            fn ($app) => new IdempotencyService(
                $app->make(Redis::class),
                config('notifications.idempotency.ttl'),
            )
        );

        $this->app->singleton(NotificationService::class,
            fn ($app) => new NotificationService(
                $app->make(NotificationRepository::class),
                $app->make(NotificationPublisher::class),
                $app->make(IdempotencyService::class),
            )
        );
    }
}
