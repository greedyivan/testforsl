<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\NotificationType;
use App\Jobs\ProcessNotificationJob;
use App\Models\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * Публикует уведомления в RabbitMQ с AMQP-приоритетом на уровне сообщения.
 *
 * Laravel's Queue::createPayload() является protected, поэтому стандартный
 * payload собирается вручную через pushRaw(). Это единственное место,
 * где знает формат payload — тесты переиспользуют buildRawPayload().
 *
 * Уровни приоритета:
 *   transactional → 10  (высший)
 *   marketing     →  1  (по умолчанию)
 */
final class NotificationPublisher
{
    public function publish(Notification $notification): void
    {
        $priority = $notification->type === NotificationType::Transactional ? 10 : 1;
        $queue    = (string) config('notifications.queue.main');
        $job      = new ProcessNotificationJob(
            notificationId: $notification->id,
            channel:        $notification->channel,
            priority:       $priority,
            batchId:        $notification->batch_id,
        );

        $payload = self::buildRawPayload($job);

        /** @var \VladimirYuldashev\LaravelQueueRabbitMQ\Queue\RabbitMQQueue $connection */
        $connection = Queue::connection('rabbitmq');
        $connection->pushRaw($payload, $queue, ['priority' => $priority]);
    }

    /**
     * Собирает стандартный Laravel job payload в виде JSON-строки.
     *
     * Вынесен в статический метод, чтобы интеграционные тесты могли
     * переиспользовать ту же логику вместо дублирования вручную.
     *
     * При мажорном обновлении Laravel проверить совместимость с
     * Illuminate\Queue\Queue::createPayloadArray().
     */
    public static function buildRawPayload(ProcessNotificationJob $job): string
    {
        return json_encode([
            'uuid'          => (string) Str::uuid(),
            'displayName'   => ProcessNotificationJob::class,
            'job'           => 'Illuminate\Queue\CallQueuedHandler@call',
            'maxTries'      => $job->tries,
            'maxExceptions' => null,
            'failOnTimeout' => false,
            'backoff'       => null,
            'timeout'       => $job->timeout ?? null,
            'retryUntil'    => null,
            'data'          => [
                'commandName' => ProcessNotificationJob::class,
                'command'     => serialize(clone $job),
            ],
        ], JSON_THROW_ON_ERROR);
    }
}
