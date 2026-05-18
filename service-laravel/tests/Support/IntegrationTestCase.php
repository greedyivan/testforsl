<?php

declare(strict_types=1);

namespace Tests\Support;

use GuzzleHttp\Client as HttpClient;
use PHPUnit\Framework\TestCase;
use Predis\Client as Redis;

/**
 * Базовый класс интеграционных тестов.
 *
 * Тесты выполняют реальные HTTP-запросы к работающему app-контейнеру и напрямую
 * взаимодействуют с Redis для управления поведением mock-провайдеров и проверки
 * логов вызовов. Laravel app внутри тестового процесса не загружается.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected HttpClient $http;
    protected Redis      $redis;

    protected function setUp(): void
    {
        parent::setUp();

        $baseUrl  = rtrim((string) (getenv('API_BASE_URL') ?: 'http://localhost:8000'), '/');
        $redisUrl = (string) (getenv('REDIS_URL') ?: 'redis://localhost:6379/1');
        $redisParsed = parse_url($redisUrl);

        $this->http = new HttpClient([
            'base_uri'        => $baseUrl,
            'timeout'         => 60.0,
            'http_errors'     => false,
            'allow_redirects' => false,
        ]);

        $this->redis = new Redis([
            'scheme'   => 'tcp',
            'host'     => $redisParsed['host']                       ?? '127.0.0.1',
            'port'     => $redisParsed['port']                       ?? 6379,
            'database' => (int) ltrim($redisParsed['path'] ?? '/1', '/'),
        ]);
    }

    protected function tearDown(): void
    {
        // Очищаем mock-ключи и ключи идемпотентности после каждого теста.
        // Используем SCAN вместо KEYS: KEYS — O(N) блокирующая операция,
        // SCAN — итеративный курсор, не блокирует сервер на время выполнения.
        $patterns = ['mock:*', 'ik:*'];
        foreach ($patterns as $pattern) {
            $cursor = '0';
            do {
                // SCAN возвращает [следующий_cursor, массив_ключей]
                [$cursor, $keys] = $this->redis->scan($cursor, ['match' => $pattern, 'count' => 100]);
                if (! empty($keys)) {
                    $this->redis->del($keys);
                }
            } while ($cursor !== '0');
        }

        parent::tearDown();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    protected function uniqueRecipient(): string
    {
        return 'test-' . substr(bin2hex(random_bytes(5)), 0, 10);
    }

    /**
     * POST /api/v1/notifications/bulk и вернуть декодированное тело ответа.
     */
    protected function bulkSend(
        array  $recipientIds,
        string $channel         = 'sms',
        string $type            = 'marketing',
        string $message         = 'Test message',
        ?string $idempotencyKey = null,
    ): array {
        $resp = $this->http->post('/api/v1/notifications/bulk', [
            'json' => [
                'channel'         => $channel,
                'type'            => $type,
                'recipient_ids'   => $recipientIds,
                'message'         => $message,
                'idempotency_key' => $idempotencyKey ?? bin2hex(random_bytes(16)),
            ],
        ]);

        $this->assertSame(202, $resp->getStatusCode(), 'bulkSend expected 202');

        return json_decode((string) $resp->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Опрашивать эндпоинт подписчика до достижения $notificationId статуса $expectedStatus.
     *
     * @throws \RuntimeException при превышении таймаута
     */
    protected function waitForStatus(
        string $recipientId,
        string $notificationId,
        string $expectedStatus,
        int    $timeoutSeconds = 30,
    ): array {
        $deadline = time() + $timeoutSeconds;

        while (time() < $deadline) {
            $resp = $this->http->get("/api/v1/subscribers/{$recipientId}/notifications");
            $body = json_decode((string) $resp->getBody(), true, 512, JSON_THROW_ON_ERROR);

            foreach ($body['notifications'] as $n) {
                if ($n['notification_id'] === $notificationId && $n['status'] === $expectedStatus) {
                    return $n;
                }
            }

            usleep(500_000); // 0.5 s
        }

        throw new \RuntimeException(
            "Уведомление {$notificationId} не перешло в статус '{$expectedStatus}' за {$timeoutSeconds} с"
        );
    }
}
