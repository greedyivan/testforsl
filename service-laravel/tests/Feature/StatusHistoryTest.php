<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Support\IntegrationTestCase;

class StatusHistoryTest extends IntegrationTestCase
{
    public function test_get_subscriber_notifications_returns_all(): void
    {
        $recipient = $this->uniqueRecipient();

        $this->bulkSend([$recipient], channel: 'sms',   idempotencyKey: bin2hex(random_bytes(8)));
        $this->bulkSend([$recipient], channel: 'email',  idempotencyKey: bin2hex(random_bytes(8)));

        sleep(5); // Allow both to settle

        $resp = $this->http->get("/api/v1/subscribers/{$recipient}/notifications");
        $body = json_decode((string) $resp->getBody(), true);

        $this->assertSame($recipient, $body['subscriber_id']);
        $this->assertGreaterThanOrEqual(2, $body['total']);
    }

    public function test_status_filter_works(): void
    {
        $recipient = $this->uniqueRecipient();
        $data      = $this->bulkSend([$recipient]);
        $notifId   = $data['notifications'][0]['notification_id'];

        $this->waitForStatus($recipient, $notifId, 'delivered');

        $resp         = $this->http->get("/api/v1/subscribers/{$recipient}/notifications", [
            'query' => ['status' => 'delivered'],
        ]);
        $body         = json_decode((string) $resp->getBody(), true);
        $notifications = $body['notifications'];

        $this->assertNotEmpty($notifications);
        foreach ($notifications as $n) {
            $this->assertSame('delivered', $n['status']);
        }
    }

    public function test_channel_filter_works(): void
    {
        $recipient = $this->uniqueRecipient();
        $this->bulkSend([$recipient], channel: 'sms',   idempotencyKey: bin2hex(random_bytes(8)));
        $this->bulkSend([$recipient], channel: 'email',  idempotencyKey: bin2hex(random_bytes(8)));

        sleep(5);

        $resp         = $this->http->get("/api/v1/subscribers/{$recipient}/notifications", [
            'query' => ['channel' => 'sms'],
        ]);
        $body         = json_decode((string) $resp->getBody(), true);
        $notifications = $body['notifications'];

        $this->assertNotEmpty($notifications);
        foreach ($notifications as $n) {
            $this->assertSame('sms', $n['channel']);
        }
    }

    public function test_total_count_reflects_actual_rows(): void
    {
        $recipient = $this->uniqueRecipient();
        $this->bulkSend([$recipient], idempotencyKey: bin2hex(random_bytes(8)));

        sleep(2);

        $resp = $this->http->get("/api/v1/subscribers/{$recipient}/notifications");
        $body = json_decode((string) $resp->getBody(), true);

        // Без пагинации total совпадает с числом элементов в ответе
        $this->assertSame($body['total'], count($body['notifications']));
    }

    public function test_pagination_limit_and_offset(): void
    {
        $recipient = $this->uniqueRecipient();

        // Создаём 3 уведомления через разные idempotency_key
        for ($i = 0; $i < 3; $i++) {
            $this->bulkSend([$recipient], idempotencyKey: bin2hex(random_bytes(8)));
        }

        sleep(2);

        // limit=2 → возвращает 2 записи, но total=3
        $resp = $this->http->get("/api/v1/subscribers/{$recipient}/notifications", [
            'query' => ['limit' => 2, 'offset' => 0],
        ]);
        $body = json_decode((string) $resp->getBody(), true);

        $this->assertSame(3, $body['total']);
        $this->assertCount(2, $body['notifications']);

        // offset=2 → остаток: 1 запись, total по-прежнему 3
        $resp = $this->http->get("/api/v1/subscribers/{$recipient}/notifications", [
            'query' => ['limit' => 2, 'offset' => 2],
        ]);
        $body = json_decode((string) $resp->getBody(), true);

        $this->assertSame(3, $body['total']);
        $this->assertCount(1, $body['notifications']);
    }
}
