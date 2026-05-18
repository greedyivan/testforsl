<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\NotificationChannel;
use App\Jobs\ProcessNotificationJob;
use App\Services\NotificationPublisher;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use Tests\Support\IntegrationTestCase;

/**
 * Проверяет, что воркер обрабатывает уведомление ровно один раз, даже при
 * повторной доставке того же AMQP-сообщения (at-least-once гарантия брокера).
 *
 * Стратегия:
 *  1. Отправить уведомление через API и дождаться доставки.
 *  2. Повторно опубликовать тот же job-payload в основную очередь
 *     (имитация redelivery брокера после краша воркера до ACK).
 *  3. Убедиться, что счётчик вызовов провайдера остался на 1.
 */
class WorkerDeduplicationTest extends IntegrationTestCase
{
    public function test_worker_deduplication_on_redelivery(): void
    {
        $recipient = $this->uniqueRecipient();

        // Шаг 1: нормальная доставка
        $data           = $this->bulkSend([$recipient], channel: 'sms');
        $notificationId = $data['notifications'][0]['notification_id'];

        $this->waitForStatus($recipient, $notificationId, 'delivered', timeoutSeconds: 30);

        // Проверяем: провайдер вызван ровно один раз
        $callCountKey = "mock:sms:call_count:{$notificationId}";
        $countBefore  = (int) ($this->redis->get($callCountKey) ?? 0);
        $this->assertSame(1, $countBefore, 'Провайдер должен быть вызван ровно 1 раз при первой доставке');

        // Шаг 2: повторная публикация того же job в RabbitMQ (имитация redelivery)
        $this->republishJob($notificationId, priority: 1);

        // Ждём, пока воркер успеет обработать дубликат
        sleep(5);

        // Шаг 3: счётчик не должен вырасти — Redis SET NX заблокировал дубликат
        $countAfter = (int) ($this->redis->get($callCountKey) ?? 0);
        $this->assertSame(1, $countAfter,
            "Провайдер вызван {$countAfter} раз(а) вместо 1 — дедупликация не сработала"
        );
    }

    /**
     * Повторно опубликовать ProcessNotificationJob payload напрямую через php-amqplib,
     * минуя Laravel queue driver — это позволяет явно задать приоритет сообщения.
     */
    private function republishJob(string $notificationId, int $priority): void
    {
        $rmqUrl = (string) (getenv('RABBITMQ_URL') ?: 'amqp://guest:guest@rabbitmq:5672/');
        $parsed = parse_url($rmqUrl);

        $connection = new AMQPStreamConnection(
            host:     $parsed['host']                       ?? 'rabbitmq',
            port:     $parsed['port']                       ?? 5672,
            user:     $parsed['user']                       ?? 'guest',
            password: $parsed['pass']                       ?? 'guest',
            vhost:    ltrim($parsed['path'] ?? '/', '/') ?: '/',
        );

        $channel   = $connection->channel();
        $queueName = (string) (getenv('MAIN_QUEUE') ?: 'notifications');

        // Переиспользуем единственный источник истины формата payload.
        // Тест отправлял с channel='sms', поэтому используем NotificationChannel::Sms.
        $job     = new ProcessNotificationJob($notificationId, NotificationChannel::Sms, $priority);
        $payload = NotificationPublisher::buildRawPayload($job);

        $message = new AMQPMessage($payload, [
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
            'priority'      => $priority,
            'content_type'  => 'application/json',
        ]);

        $channel->basic_publish($message, '', $queueName);
        $channel->close();
        $connection->close();
    }
}
