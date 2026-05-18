<?php

declare(strict_types=1);

namespace Tests\Feature;

use GuzzleHttp\Client as HttpClient;
use Tests\Support\IntegrationTestCase;

/**
 * Проверяет конфигурацию очереди с приоритетами RabbitMQ и порядок обработки сообщений.
 */
class PriorityTest extends IntegrationTestCase
{
    // Trailing slash обязателен: Guzzle корректно добавляет относительные пути.
    // Без него путь "queues/..." заменяет "/api" вместо дополнения.
    private const RABBITMQ_API  = 'http://rabbitmq:15672/api/';
    private const RABBITMQ_AUTH = ['guest', 'guest'];

    public function test_main_queue_has_priority_argument(): void
    {
        $mgmt = new HttpClient([
            'base_uri'    => self::RABBITMQ_API,
            'auth'        => self::RABBITMQ_AUTH,
            'timeout'     => 10.0,
            'http_errors' => false,
        ]);

        $resp = $mgmt->get('queues/%2F/notifications');

        if ($resp->getStatusCode() === 404) {
            $this->markTestSkipped('Queue not yet declared');
        }

        $this->assertSame(200, $resp->getStatusCode());
        $info = json_decode((string) $resp->getBody(), true);
        $args = $info['arguments'] ?? [];

        $this->assertSame(10, $args['x-max-priority'] ?? null,
            'Expected x-max-priority=10 on main queue'
        );
    }

    public function test_retry_queue_has_ttl(): void
    {
        $mgmt = new HttpClient([
            'base_uri'    => self::RABBITMQ_API,
            'auth'        => self::RABBITMQ_AUTH,
            'timeout'     => 10.0,
            'http_errors' => false,
        ]);

        $resp = $mgmt->get('queues/%2F/notifications.retry');

        if ($resp->getStatusCode() === 404) {
            $this->markTestSkipped('Retry queue not yet declared');
        }

        $this->assertSame(200, $resp->getStatusCode());
        $args = json_decode((string) $resp->getBody(), true)['arguments'] ?? [];

        $this->assertArrayHasKey('x-message-ttl', $args);
    }

    public function test_transactional_processed_before_bulk_marketing(): void
    {
        $nMarketing         = 30;
        $marketingRecipients = [];

        for ($i = 0; $i < $nMarketing; $i++) {
            $marketingRecipients[] = $this->uniqueRecipient();
        }
        $txRecipient = $this->uniqueRecipient();

        // Замедляем воркер для каждого маркетингового получателя (0.3 с на сообщение)
        foreach ($marketingRecipients as $r) {
            $this->redis->set("mock:sms:behavior:{$r}", 'slow');
        }

        // Публикуем маркетинговый батч — воркер начинает потребление немедленно
        $this->bulkSend(
            $marketingRecipients,
            channel:        'sms',
            type:           'marketing',
            idempotencyKey: bin2hex(random_bytes(16)),
        );

        // Транзакционное — сразу после батча; попадает в очередь с priority 10
        $txData    = $this->bulkSend(
            [$txRecipient],
            channel:        'sms',
            type:           'transactional',
            idempotencyKey: bin2hex(random_bytes(16)),
            message:        'URGENT: your verification code',
        );
        $txNotifId = $txData['notifications'][0]['notification_id'];

        // 30 медленных × 0.3 с = 9 с + запас → 60 с
        $this->waitForStatus($txRecipient, $txNotifId, 'delivered', timeoutSeconds: 60);

        // Проверяем порядок вызовов в Redis call-log
        $calls   = $this->redis->lrange('mock:sms:calls', 0, -1);
        $callIds = array_map('strval', $calls);

        $this->assertContains($txNotifId, $callIds,
            'Транзакционное уведомление не найдено в call-log провайдера'
        );

        $txPosition = array_search($txNotifId, $callIds, true);

        // Транзакционное должно быть обработано раньше хотя бы половины маркетинговых —
        // явный признак работающей приоритизации.
        $this->assertLessThan(
            (int) ($nMarketing / 2),
            $txPosition,
            "Транзакционное на позиции {$txPosition}, ожидалось < " . ($nMarketing / 2)
        );
    }

    public function test_transactional_type_stored_correctly(): void
    {
        $recipient = $this->uniqueRecipient();
        $data      = $this->bulkSend(
            [$recipient],
            type:           'transactional',
            idempotencyKey: bin2hex(random_bytes(8)),
        );
        $notifId = $data['notifications'][0]['notification_id'];

        $this->waitForStatus($recipient, $notifId, 'delivered');

        $resp  = $this->http->get("/api/v1/subscribers/{$recipient}/notifications");
        $body  = json_decode((string) $resp->getBody(), true);
        $notif = $body['notifications'][0];

        $this->assertSame('transactional', $notif['type']);
    }
}
