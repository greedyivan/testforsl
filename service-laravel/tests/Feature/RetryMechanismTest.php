<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Support\IntegrationTestCase;

class RetryMechanismTest extends IntegrationTestCase
{
    public function test_temporary_failure_retried_and_delivered(): void
    {
        $recipient = $this->uniqueRecipient();

        // Настраиваем SMS-заглушку: 2 сбоя, затем успех
        $this->redis->set("mock:sms:behavior:{$recipient}", 'temporary_fail');
        $this->redis->set("mock:sms:fail_count:{$recipient}", '2');

        $data    = $this->bulkSend([$recipient], channel: 'sms');
        $notifId = $data['notifications'][0]['notification_id'];

        // RETRY_TTL_MS на каждый ретрай; даём запас по времени
        $result = $this->waitForStatus($recipient, $notifId, 'delivered', timeoutSeconds: 60);

        $this->assertGreaterThanOrEqual(2, $result['retry_count']);
    }

    public function test_permanent_failure_marks_dropped(): void
    {
        $recipient = $this->uniqueRecipient();
        $this->redis->set("mock:sms:behavior:{$recipient}", 'permanent_fail');

        $data    = $this->bulkSend([$recipient], channel: 'sms');
        $notifId = $data['notifications'][0]['notification_id'];

        $result = $this->waitForStatus($recipient, $notifId, 'dropped', timeoutSeconds: 20);

        $this->assertNotNull($result['error_message']);
    }

    public function test_max_retries_exceeded_marks_dropped(): void
    {
        $recipient = $this->uniqueRecipient();

        // Сбоить всегда (без fail_count → бесконечный сбой), превысит MAX_RETRIES
        $this->redis->set("mock:sms:behavior:{$recipient}", 'temporary_fail');

        $data    = $this->bulkSend([$recipient], channel: 'sms');
        $notifId = $data['notifications'][0]['notification_id'];

        // MAX_RETRIES=3, RETRY_TTL_MS=5000 → до 4 попыток × 5 с = ~20 с
        $result = $this->waitForStatus($recipient, $notifId, 'dropped', timeoutSeconds: 90);

        $this->assertGreaterThanOrEqual(3, $result['retry_count']);
    }
}
