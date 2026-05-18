<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\IdempotencyService;
use PHPUnit\Framework\TestCase;
use Predis\Client as Redis;

/**
 * Unit-тесты IdempotencyService на реальном Redis (DB index 2).
 * Запускаются внутри тестового контейнера, где Redis доступен.
 */
class IdempotencyServiceTest extends TestCase
{
    private Redis            $redis;
    private IdempotencyService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $redisUrl    = (string) (getenv('REDIS_URL') ?: 'redis://redis:6379/1');
        $parsed      = parse_url($redisUrl);

        // Отдельный DB index, чтобы не пересекаться с ключами интеграционных тестов
        $this->redis = new Redis([
            'scheme'   => 'tcp',
            'host'     => $parsed['host'] ?? 'redis',
            'port'     => $parsed['port'] ?? 6379,
            'database' => 2,
        ]);

        $this->redis->flushdb();
        $this->service = new IdempotencyService($this->redis);
    }

    protected function tearDown(): void
    {
        $this->redis->flushdb();
        parent::tearDown();
    }

    // ── Уровень API ───────────────────────────────────────────────────────────

    public function test_get_returns_null_for_unknown_key(): void
    {
        $result = $this->service->getApiResponse('nonexistent-key');
        $this->assertNull($result);
    }

    public function test_set_and_get_round_trip(): void
    {
        $key     = bin2hex(random_bytes(8));
        $payload = ['batch_id' => bin2hex(random_bytes(8)), 'accepted' => 3];

        $this->service->setApiResponse($key, $payload);
        $result = $this->service->getApiResponse($key);

        $this->assertSame($payload, $result);
    }

    public function test_second_set_overwrites_first(): void
    {
        $key    = bin2hex(random_bytes(8));
        $first  = ['value' => 'first'];
        $second = ['value' => 'second'];

        $this->service->setApiResponse($key, $first);
        $this->service->setApiResponse($key, $second);

        $this->assertSame($second, $this->service->getApiResponse($key));
    }

    // ── Уровень воркера ───────────────────────────────────────────────────────

    public function test_first_claim_returns_true(): void
    {
        $id = bin2hex(random_bytes(8));
        $this->assertTrue($this->service->tryClaimWorker($id));
    }

    public function test_second_claim_same_id_returns_false(): void
    {
        $id = bin2hex(random_bytes(8));
        $this->service->tryClaimWorker($id);
        $this->assertFalse($this->service->tryClaimWorker($id));
    }

    public function test_different_ids_both_succeed(): void
    {
        $id1 = bin2hex(random_bytes(8));
        $id2 = bin2hex(random_bytes(8));
        $this->assertTrue($this->service->tryClaimWorker($id1));
        $this->assertTrue($this->service->tryClaimWorker($id2));
    }

    public function test_release_allows_reclaim(): void
    {
        $id = bin2hex(random_bytes(8));
        $this->service->tryClaimWorker($id);
        $this->assertFalse($this->service->tryClaimWorker($id)); // blocked

        $this->service->releaseWorkerClaim($id);
        $this->assertTrue($this->service->tryClaimWorker($id));  // allowed again
    }
}
