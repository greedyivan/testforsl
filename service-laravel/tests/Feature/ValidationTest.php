<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Support\IntegrationTestCase;

class ValidationTest extends IntegrationTestCase
{
    public function test_invalid_channel_returns_422(): void
    {
        $resp = $this->http->post('/api/v1/notifications/bulk', [
            'json' => [
                'channel'         => 'telegram',
                'type'            => 'marketing',
                'recipient_ids'   => ['r1'],
                'message'         => 'Hi',
                'idempotency_key' => 'k1',
            ],
        ]);

        $this->assertSame(422, $resp->getStatusCode());
    }

    public function test_missing_recipient_ids_returns_422(): void
    {
        $resp = $this->http->post('/api/v1/notifications/bulk', [
            'json' => [
                'channel'         => 'sms',
                'type'            => 'marketing',
                'message'         => 'Hi',
                'idempotency_key' => 'k2',
            ],
        ]);

        $this->assertSame(422, $resp->getStatusCode());
    }

    public function test_empty_recipient_ids_returns_422(): void
    {
        $resp = $this->http->post('/api/v1/notifications/bulk', [
            'json' => [
                'channel'         => 'sms',
                'type'            => 'marketing',
                'recipient_ids'   => [],
                'message'         => 'Hi',
                'idempotency_key' => 'k3',
            ],
        ]);

        $this->assertSame(422, $resp->getStatusCode());
    }
}
