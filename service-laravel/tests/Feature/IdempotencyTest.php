<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Support\IntegrationTestCase;

class IdempotencyTest extends IntegrationTestCase
{
    public function test_duplicate_api_request_returns_same_response(): void
    {
        $recipients = [$this->uniqueRecipient(), $this->uniqueRecipient()];
        $key        = bin2hex(random_bytes(16));

        $payload = [
            'json' => [
                'channel'         => 'sms',
                'type'            => 'marketing',
                'message'         => 'Idempotency test',
                'recipient_ids'   => $recipients,
                'idempotency_key' => $key,
            ],
        ];

        $r1 = $this->http->post('/api/v1/notifications/bulk', $payload);
        $r2 = $this->http->post('/api/v1/notifications/bulk', $payload);

        $this->assertSame(202, $r1->getStatusCode());
        $this->assertSame(202, $r2->getStatusCode());

        $b1 = json_decode((string) $r1->getBody(), true);
        $b2 = json_decode((string) $r2->getBody(), true);

        $this->assertSame($b1['batch_id'],  $b2['batch_id']);
        $this->assertSame($b1['accepted'],  $b2['accepted']);
    }

    public function test_duplicate_api_request_creates_no_extra_notifications(): void
    {
        $recipient = $this->uniqueRecipient();
        $key       = bin2hex(random_bytes(16));

        $payload = [
            'json' => [
                'channel'         => 'sms',
                'type'            => 'marketing',
                'message'         => 'Idempotency dedup test',
                'recipient_ids'   => [$recipient],
                'idempotency_key' => $key,
            ],
        ];

        $this->http->post('/api/v1/notifications/bulk', $payload);
        $this->http->post('/api/v1/notifications/bulk', $payload);

        sleep(4);

        $resp = $this->http->get("/api/v1/subscribers/{$recipient}/notifications");
        $body = json_decode((string) $resp->getBody(), true);

        $this->assertSame(1, $body['total']); // ровно одна запись, не две
    }
}
