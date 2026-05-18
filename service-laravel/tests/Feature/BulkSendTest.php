<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Support\IntegrationTestCase;

class BulkSendTest extends IntegrationTestCase
{
    public function test_returns_202_with_notification_list(): void
    {
        $recipients = [$this->uniqueRecipient(), $this->uniqueRecipient(), $this->uniqueRecipient()];
        $data       = $this->bulkSend($recipients);

        $this->assertSame(3, $data['accepted']);
        $this->assertCount(3, $data['notifications']);

        $statuses = array_unique(array_column($data['notifications'], 'status'));
        $this->assertSame(['queued'], $statuses);
    }

    public function test_each_recipient_gets_own_notification(): void
    {
        $r1 = $this->uniqueRecipient();
        $r2 = $this->uniqueRecipient();

        $data         = $this->bulkSend([$r1, $r2]);
        $recipientIds = array_column($data['notifications'], 'recipient_id');

        $this->assertContains($r1, $recipientIds);
        $this->assertContains($r2, $recipientIds);
    }

    public function test_batch_id_is_consistent(): void
    {
        $recipient = $this->uniqueRecipient();
        $data      = $this->bulkSend([$recipient]);
        $batchId   = $data['batch_id'];

        $this->assertNotEmpty($batchId);

        $resp = $this->http->get("/api/v1/subscribers/{$recipient}/notifications");
        $body = json_decode((string) $resp->getBody(), true);

        $this->assertSame($batchId, $body['notifications'][0]['batch_id']);
    }
}
