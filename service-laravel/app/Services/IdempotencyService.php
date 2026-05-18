<?php

declare(strict_types=1);

namespace App\Services;

use Predis\Client as Redis;

/**
 * Двухуровневая дедупликация через Redis.
 *
 * Уровень 1 (API):    предотвращает повторные bulk-send запросы от клиента.
 * Уровень 2 (Worker): защищает от повторной доставки at-least-once брокера —
 *                     один notification_id обрабатывается ровно один раз.
 */
final class IdempotencyService
{
    private const API_PREFIX    = 'ik:api:';
    private const WORKER_PREFIX = 'ik:worker:';

    // Значение по умолчанию — 24 часа. Используется в unit-тестах,
    // где сервис создаётся без IoC-контейнера Laravel.
    private const DEFAULT_TTL = 86_400;

    public function __construct(
        private readonly Redis $redis,
        private readonly int   $ttl = self::DEFAULT_TTL,
    ) {}

    // ── Уровень API ───────────────────────────────────────────────────────────

    public function getApiResponse(string $key): ?array
    {
        $raw = $this->redis->get(self::API_PREFIX . $key);

        return $raw !== null ? json_decode($raw, true) : null;
    }

    public function setApiResponse(string $key, array $response): void
    {
        $this->redis->setex(
            self::API_PREFIX . $key,
            $this->ttl,
            json_encode($response, JSON_THROW_ON_ERROR),
        );
    }

    // ── Уровень воркера ───────────────────────────────────────────────────────

    /**
     * Атомарно захватить уведомление для обработки (Redis SET NX).
     *
     * Возвращает true  — этот воркер первый; продолжить доставку.
     * Возвращает false — другой экземпляр воркера уже захватил уведомление
     *                    (сценарий дублирующей доставки at-least-once).
     */
    public function tryClaimWorker(string $notificationId): bool
    {
        $ttl = $this->ttl;

        $result = $this->redis->set(
            self::WORKER_PREFIX . $notificationId,
            '1',
            'EX', $ttl,
            'NX',
        );

        return $result !== null;
    }

    /**
     * Освободить Redis-claim, чтобы следующая попытка могла его захватить заново.
     * Вызывается при перехвате TemporaryProviderException.
     */
    public function releaseWorkerClaim(string $notificationId): void
    {
        $this->redis->del([self::WORKER_PREFIX . $notificationId]);
    }

}
