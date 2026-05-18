<?php

declare(strict_types=1);

namespace App\Providers\Mocks;

use App\Contracts\NotificationProvider;
use App\Exceptions\PermanentProviderException;
use App\Exceptions\TemporaryProviderException;
use App\Models\Notification;
use Predis\Client as Redis;

/**
 * Базовый класс для mock-провайдеров SMS и Email.
 *
 * Поведение для каждого получателя управляется через Redis-ключи,
 * которые устанавливают интеграционные тесты:
 *
 *   mock:{channel}:behavior:{recipient_id}   → 'temporary_fail' | 'permanent_fail' | 'slow'
 *   mock:{channel}:fail_count:{recipient_id} → оставшееся число сбоев до переключения на успех
 *   mock:{channel}:calls                     → RPUSH-список notification_id (лог вызовов)
 *   mock:{channel}:call_count:{notif_id}     → INCR-счётчик вызовов для каждого уведомления
 *
 * Ключи совпадают с теми, что используются в Python-сервисе, чтобы
 * одни и те же Redis-фикстуры работали для обеих реализаций.
 */
abstract class MockNotificationProvider implements NotificationProvider
{
    abstract protected function channel(): string;

    public function __construct(protected readonly Redis $redis) {}

    final public function send(Notification $notification): void
    {
        $behavior = $this->getBehavior($notification->recipient_id);

        match ($behavior) {
            'temporary_fail' => $this->handleTemporaryFail($notification->recipient_id),
            'permanent_fail' => throw new PermanentProviderException(
                "Mock {$this->channel()}: permanent failure for {$notification->recipient_id}"
            ),
            'slow' => $this->applySlow(),
            default => null, // success
        };

        $this->recordCall($notification->id);
    }

    private function getBehavior(string $recipientId): ?string
    {
        $key = "mock:{$this->channel()}:behavior:{$recipientId}";
        $val = $this->redis->get($key);

        return $val !== null ? (string) $val : null;
    }

    private function handleTemporaryFail(string $recipientId): void
    {
        $failCountKey = "mock:{$this->channel()}:fail_count:{$recipientId}";
        $behaviorKey  = "mock:{$this->channel()}:behavior:{$recipientId}";

        // Lua-скрипт выполняется атомарно: проверяет наличие fail_count,
        // декрементирует его и при исчерпании удаляет оба ключа за одну операцию.
        //
        // Проблема простого DECR: он создаёт ключ со значением -1, если его
        // не существует. Если fail_count не задан, behavior должен сохраняться
        // бесконечно (поведение "сбоить всегда"), а не удаляться после первого вызова.
        //
        // Lua-скрипт возвращает -1, если ключа fail_count нет (ничего не меняем),
        // или новое значение счётчика после декремента.
        $lua = <<<'LUA'
            local count = redis.call('GET', KEYS[1])
            if count == false then return -1 end
            local newCount = redis.call('DECR', KEYS[1])
            if newCount <= 0 then redis.call('DEL', KEYS[1], KEYS[2]) end
            return newCount
        LUA;

        // Predis::eval($script, $numkeys, ...$keys_and_args)
        $this->redis->eval($lua, 2, $failCountKey, $behaviorKey);

        throw new TemporaryProviderException(
            "Mock {$this->channel()}: temporary failure for {$recipientId}"
        );
    }

    protected function applySlow(): void
    {
        // 300 мс задержка — держит воркер занятым достаточно долго для priority-тестов
        usleep(300_000);
    }

    private function recordCall(string $notificationId): void
    {
        $callsKey     = "mock:{$this->channel()}:calls";
        $callCountKey = "mock:{$this->channel()}:call_count:{$notificationId}";

        $this->redis->rpush($callsKey, [$notificationId]);
        $this->redis->incr($callCountKey);
    }
}
