<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Support\IntegrationTestCase;

class DeliveryFlowTest extends IntegrationTestCase
{
    public function test_sms_notification_reaches_delivered(): void
    {
        $recipient = $this->uniqueRecipient();
        $data      = $this->bulkSend([$recipient], channel: 'sms');
        $notifId   = $data['notifications'][0]['notification_id'];

        $result = $this->waitForStatus($recipient, $notifId, 'delivered');

        $this->assertSame('sms', $result['channel']);
        $this->assertNotNull($result['delivered_at']);
        $this->assertNotNull($result['sent_at']);
    }

    public function test_email_notification_reaches_delivered(): void
    {
        $recipient = $this->uniqueRecipient();
        $data      = $this->bulkSend([$recipient], channel: 'email');
        $notifId   = $data['notifications'][0]['notification_id'];

        $result = $this->waitForStatus($recipient, $notifId, 'delivered');

        $this->assertSame('email', $result['channel']);
    }

    public function test_transactional_notification_delivered(): void
    {
        $recipient = $this->uniqueRecipient();
        $data      = $this->bulkSend([$recipient], channel: 'sms', type: 'transactional');
        $notifId   = $data['notifications'][0]['notification_id'];

        $result = $this->waitForStatus($recipient, $notifId, 'delivered');

        $this->assertSame('transactional', $result['type']);
    }
}
