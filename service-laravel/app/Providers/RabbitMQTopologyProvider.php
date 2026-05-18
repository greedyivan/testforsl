<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * Идемпотентно объявляет полную топологию RabbitMQ при старте приложения.
 *
 * Топология:
 *   notifications.dlx           — direct exchange для dead-letter из основной очереди
 *   notifications.dead.exchange — fanout exchange для "мёртвых" сообщений
 *   notifications.dead          — durable-очередь, привязана к dead.exchange
 *   notifications.retry         — durable-очередь с x-message-ttl; после TTL
 *                                 сообщения возвращаются в основную очередь через default exchange
 *   notifications               — основная очередь; x-max-priority=10, DLX → retry-очередь
 *
 * Выполняется только при старте HTTP-сервера или воркера очереди.
 * Пропускается для всех остальных artisan-команд (migrate, package:discover и т.д.),
 * чтобы сборка Docker-образа не требовала живого RabbitMQ.
 */
class RabbitMQTopologyProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole() && ! $this->app->runningUnitTests()) {
            $caller = $_SERVER['argv'][1] ?? '';
            // Разрешаем объявление топологии только при старте HTTP-сервера
            // или воркера очереди — все остальные artisan-команды (migrate,
            // package:discover и т.д.) не должны требовать живого RabbitMQ.
            if (! in_array($caller, ['serve', 'queue:work', 'queue:listen', 'rabbitmq:consume'], true)) {
                return;
            }
        }

        try {
            $this->declareTopology();
        } catch (\Throwable $e) {
            // Не падаем — RabbitMQ может ещё запускаться при старте контейнера.
            // Ошибка залогируется; воркер подключится при следующей попытке.
            Log::warning('RabbitMQTopologyProvider: failed to declare topology', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function declareTopology(): void
    {
        // Параметры соединения берём из config/queue.php, который уже разобрал
        // RABBITMQ_URL через parse_url(). Это единственный источник истины —
        // дублировать парсинг env-переменной здесь не нужно.
        $rmq = config('queue.connections.rabbitmq.hosts.0', []);

        $connection = new AMQPStreamConnection(
            host:               (string) ($rmq['host']     ?? 'localhost'),
            port:               (int)    ($rmq['port']     ?? 5672),
            user:               (string) ($rmq['user']     ?? 'guest'),
            password:           (string) ($rmq['password'] ?? 'guest'),
            vhost:              (string) ($rmq['vhost']    ?? '/'),
            insist:             false,
            login_method:       'AMQPLAIN',
            login_response:     null,
            locale:             'en_US',
            connection_timeout: 5.0,
            read_write_timeout: 30.0,
            keepalive:          false,
            heartbeat:          60,
        );

        $channel   = $connection->channel();
        $mainQueue    = (string) config('notifications.queue.main');
        $retryTtl     = (int)    config('notifications.queue.retry_ttl_ms');
        $dlxExchange  = (string) config('notifications.queue.dlx_exchange');
        $retryQueue   = (string) config('notifications.queue.retry');
        $deadExchange = (string) config('notifications.queue.dead_exchange');
        $deadQueue    = (string) config('notifications.queue.dead');

        // DLX — direct exchange; принимает dead-lettered сообщения из основной очереди
        $channel->exchange_declare($dlxExchange, 'direct', false, true, false);

        // Dead-letter exchange — fanout; принимает «ядовитые» (неисправимые) сообщения
        $channel->exchange_declare($deadExchange, 'fanout', false, true, false);

        // Dead queue — постоянное хранилище недоставляемых сообщений
        $channel->queue_declare($deadQueue, false, true, false, false, false);
        $channel->queue_bind($deadQueue, $deadExchange);

        // Retry queue — сообщения находятся здесь RETRY_TTL_MS мс, затем возвращаются в основную очередь
        $channel->queue_declare(
            queue:       $retryQueue,
            passive:     false,
            durable:     true,
            exclusive:   false,
            auto_delete: false,
            nowait:      false,
            arguments:   new AMQPTable([
                'x-dead-letter-exchange'    => '',       // default exchange
                'x-dead-letter-routing-key' => $mainQueue,
                'x-message-ttl'             => $retryTtl,
            ]),
        );

        // Основная очередь — поддержка приоритетов + dead-letter в DLX → retry queue
        $channel->queue_declare(
            queue:       $mainQueue,
            passive:     false,
            durable:     true,
            exclusive:   false,
            auto_delete: false,
            nowait:      false,
            arguments:   new AMQPTable([
                'x-max-priority'            => 10,
                'x-dead-letter-exchange'    => $dlxExchange,
                'x-dead-letter-routing-key' => $retryQueue,
            ]),
        );
        $channel->queue_bind($retryQueue, $dlxExchange, $retryQueue);

        $channel->close();
        $connection->close();
    }
}
