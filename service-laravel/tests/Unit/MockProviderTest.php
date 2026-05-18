<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Enums\NotificationType;
use App\Exceptions\PermanentProviderException;
use App\Exceptions\TemporaryProviderException;
use App\Models\Notification;
use App\Providers\Mocks\EmailMockProvider;
use App\Providers\Mocks\SmsMockProvider;
use PHPUnit\Framework\TestCase;
use Predis\Client as Redis;

class MockProviderTest extends TestCase
{
    private Redis $redis;

    protected function setUp(): void
    {
        parent::setUp();

        $redisUrl = (string) (getenv('REDIS_URL') ?: 'redis://redis:6379/1');
        $parsed   = parse_url($redisUrl);

        $this->redis = new Redis([
            'scheme'   => 'tcp',
            'host'     => $parsed['host'] ?? 'redis',
            'port'     => $parsed['port'] ?? 6379,
            'database' => 2,
        ]);

        $this->redis->flushdb();
    }

    protected function tearDown(): void
    {
        $this->redis->flushdb();
        parent::tearDown();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeNotification(string $channel, string $recipientId = 'test-recipient'): Notification
    {
        $n               = new Notification();
        $n->id           = bin2hex(random_bytes(8));
        $n->batch_id     = bin2hex(random_bytes(8));
        $n->recipient_id = $recipientId;
        $n->channel      = NotificationChannel::from($channel);
        $n->type         = NotificationType::Marketing;
        $n->message      = 'Test';
        $n->status       = NotificationStatus::Queued;
        $n->retry_count  = 0;

        return $n;
    }

    // ── SMS-провайдер ─────────────────────────────────────────────────────────

    public function test_sms_success_records_call(): void
    {
        $provider = new SmsMockProvider($this->redis);
        $n        = $this->makeNotification('sms');

        $provider->send($n);

        $calls = $this->redis->lrange('mock:sms:calls', 0, -1);
        $this->assertContains($n->id, $calls);
    }

    public function test_sms_temporary_fail_raises_exception(): void
    {
        $recipient = 'fail-' . bin2hex(random_bytes(4));
        $this->redis->set("mock:sms:behavior:{$recipient}", 'temporary_fail');

        $provider = new SmsMockProvider($this->redis);
        $n        = $this->makeNotification('sms', $recipient);

        $this->expectException(TemporaryProviderException::class);
        $provider->send($n);
    }

    public function test_sms_permanent_fail_raises_exception(): void
    {
        $recipient = 'pfail-' . bin2hex(random_bytes(4));
        $this->redis->set("mock:sms:behavior:{$recipient}", 'permanent_fail');

        $provider = new SmsMockProvider($this->redis);
        $n        = $this->makeNotification('sms', $recipient);

        $this->expectException(PermanentProviderException::class);
        $provider->send($n);
    }

    public function test_sms_fail_count_decrements_to_success(): void
    {
        $recipient = 'count-' . bin2hex(random_bytes(4));
        $this->redis->set("mock:sms:behavior:{$recipient}", 'temporary_fail');
        $this->redis->set("mock:sms:fail_count:{$recipient}", '1');

        $provider = new SmsMockProvider($this->redis);
        $n        = $this->makeNotification('sms', $recipient);

        // Первый вызов: сбой
        try {
            $provider->send($n);
            $this->fail('Expected TemporaryProviderException');
        } catch (TemporaryProviderException) {
        }

        // Второй вызов: успех (fail_count исчерпан, ключи удалены)
        $provider->send($n);
        $calls = $this->redis->lrange('mock:sms:calls', 0, -1);
        $this->assertContains($n->id, $calls);
    }

    // ── Email-провайдер ───────────────────────────────────────────────────────

    public function test_email_success_records_call(): void
    {
        $provider = new EmailMockProvider($this->redis);
        $n        = $this->makeNotification('email');

        $provider->send($n);

        $calls = $this->redis->lrange('mock:email:calls', 0, -1);
        $this->assertContains($n->id, $calls);
    }

    public function test_email_temporary_fail_raises_exception(): void
    {
        $recipient = 'efail-' . bin2hex(random_bytes(4));
        $this->redis->set("mock:email:behavior:{$recipient}", 'temporary_fail');

        $provider = new EmailMockProvider($this->redis);
        $n        = $this->makeNotification('email', $recipient);

        $this->expectException(TemporaryProviderException::class);
        $provider->send($n);
    }

    public function test_email_permanent_fail_raises_exception(): void
    {
        $recipient = 'epfail-' . bin2hex(random_bytes(4));
        $this->redis->set("mock:email:behavior:{$recipient}", 'permanent_fail');

        $provider = new EmailMockProvider($this->redis);
        $n        = $this->makeNotification('email', $recipient);

        $this->expectException(PermanentProviderException::class);
        $provider->send($n);
    }

    public function test_email_fail_count_decrements_to_success(): void
    {
        $recipient = 'ecount-' . bin2hex(random_bytes(4));
        $this->redis->set("mock:email:behavior:{$recipient}", 'temporary_fail');
        $this->redis->set("mock:email:fail_count:{$recipient}", '1');

        $provider = new EmailMockProvider($this->redis);
        $n        = $this->makeNotification('email', $recipient);

        // Первый вызов: сбой
        try {
            $provider->send($n);
            $this->fail('Expected TemporaryProviderException');
        } catch (TemporaryProviderException) {
        }

        // Второй вызов: успех (fail_count исчерпан, ключи удалены)
        $provider->send($n);
        $calls = $this->redis->lrange('mock:email:calls', 0, -1);
        $this->assertContains($n->id, $calls);
    }
}
