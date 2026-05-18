<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\NotificationChannel;
use App\Exceptions\PermanentProviderException;
use App\Exceptions\TemporaryProviderException;
use App\Repositories\NotificationRepository;
use App\Services\IdempotencyService;
use App\Services\ProviderFactory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Обрабатывает одно уведомление через пайплайн доставки.
 *
 * Контракт повторных попыток
 * ──────────────────────────
 * $tries задаётся при конструировании (MAX_RETRIES + 1) и сериализуется
 * вместе с job, поэтому воркер использует значение, зафиксированное в момент диспатча.
 *
 * При TemporaryProviderException:
 *   1. Увеличиваем retry_count в БД.
 *   2. Освобождаем Redis-claim, чтобы следующая попытка могла его захватить.
 *   3. Сбрасываем статус уведомления в "queued", чтобы tryClaimForProcessing() сработал.
 *   4. Пробрасываем исключение → Laravel повторно ставит job в очередь (с backoff).
 *
 * При PermanentProviderException:
 *   Немедленно помечаем как "dropped"; вызываем $this->fail() для прекращения попыток.
 *
 * При исчерпании всех попыток:
 *   failed() помечает как "dropped" (idempotent — двойная пометка безопасна).
 *
 * Дедупликация на уровне воркера (защита от at-least-once):
 *   На ПЕРВОЙ попытке ($this->attempts() == 1) требуем успешный Redis SET NX.
 *   Если ключ уже существует — это повторная доставка того же сообщения брокером,
 *   тихо игнорируем (ACK без обработки).
 *   На ПОСЛЕДУЮЩИХ попытках ключ был удалён обработчиком ошибки, SET NX снова срабатывает.
 */
class ProcessNotificationJob implements ShouldQueue
{
    use Queueable;

    public int $tries;
    public int $timeout = 60;

    public function __construct(
        public readonly string              $notificationId,
        public readonly NotificationChannel $channel,
        public readonly int                 $priority = 1,
        public readonly string              $batchId = '',
    ) {
        // В production-контекстах (диспатч, воркер) приложение загружено полностью,
        // поэтому используем config() — он корректно работает с php artisan config:cache,
        // где env() вернул бы null. Если контейнер не готов (прямой new в интеграционных
        // тестах, где Laravel не загружается), падаем обратно на env().
        try {
            $this->tries = (int) config('notifications.queue.max_retries', 3) + 1;
        } catch (\Throwable) {
            $this->tries = (int) env('MAX_RETRIES', 3) + 1;
        }
    }

    public function backoff(): int
    {
        // Вызывается только внутри воркера (booted app) — config() безопасен.
        // Минимум 1 с, чтобы воркер не перегружал брокер при ретраях.
        return max(1, (int) (config('notifications.queue.retry_ttl_ms') / 1_000));
    }

    public function handle(
        NotificationRepository $repo,
        IdempotencyService     $idempotency,
        ProviderFactory        $factory,
    ): void {
        $attemptNumber = $this->attempts();
        $maxRetries    = config('notifications.queue.max_retries');

        Log::info('ProcessNotificationJob: processing', [
            'notification_id' => $this->notificationId,
            'batch_id'        => $this->batchId,
            'attempt'         => $attemptNumber,
        ]);

        // Защитный клапан — превышен лимит попыток (в норме обрабатывается через failed())
        if ($attemptNumber > $maxRetries + 1) {
            return;
        }

        // ── Дедупликация на уровне воркера ────────────────────────────────────
        // Только первая доставка: если Redis-ключ уже существует — это повторная
        // доставка at-least-once, тихо отбрасываем сообщение (ACK без обработки).
        $claimed = $idempotency->tryClaimWorker($this->notificationId);
        if (! $claimed && $attemptNumber === 1) {
            Log::info('ProcessNotificationJob: повторная доставка обнаружена, отбрасываем', [
                'notification_id' => $this->notificationId,
                'batch_id'        => $this->batchId,
            ]);
            return;
        }

        // ── Атомарный переход статуса: queued|sent → sent ─────────────────────
        // tryClaimForProcessing возвращает bool (только UPDATE, без SELECT).
        // Channel уже известен из поля job — дополнительный SELECT не нужен
        // для определения провайдера, даже если claim прошёл успешно.
        if (! $repo->tryClaimForProcessing($this->notificationId)) {
            // Уведомление уже в терминальном состоянии (concurrent обработка).
            Log::info('ProcessNotificationJob: уведомление уже в терминальном состоянии', [
                'notification_id' => $this->notificationId,
                'batch_id'        => $this->batchId,
            ]);
            return;
        }

        // Загружаем модель для передачи в провайдер (нужны recipient_id, message и т.д.).
        // SELECT выполняется только после успешного claim, а не внутри него.
        $notification = $repo->find($this->notificationId);
        if ($notification === null) {
            // Крайний случай: запись исчезла между claim и SELECT (например, ручное удаление).
            Log::warning('ProcessNotificationJob: уведомление не найдено после claim', [
                'notification_id' => $this->notificationId,
            ]);
            return;
        }

        // ── Вызов провайдера ──────────────────────────────────────────────────
        try {
            $provider = $factory->get($this->channel);
            $provider->send($notification);
            $repo->markDelivered($this->notificationId);

            Log::info('ProcessNotificationJob: доставлено', [
                'notification_id' => $this->notificationId,
                'batch_id'        => $this->batchId,
            ]);
        } catch (TemporaryProviderException $e) {
            Log::warning('ProcessNotificationJob: временный сбой, будет повтор', [
                'notification_id' => $this->notificationId,
                'batch_id'        => $this->batchId,
                'error'           => $e->getMessage(),
            ]);

            // Подготовка к следующей попытке:
            $repo->incrementRetryCount($this->notificationId);
            $idempotency->releaseWorkerClaim($this->notificationId);
            $repo->resetToQueued($this->notificationId);

            throw $e; // Laravel повторно поставит job в очередь (с backoff).
        } catch (PermanentProviderException $e) {
            Log::error('ProcessNotificationJob: постоянный сбой, отбрасываем', [
                'notification_id' => $this->notificationId,
                'batch_id'        => $this->batchId,
                'error'           => $e->getMessage(),
            ]);

            $repo->markDropped($this->notificationId, $e->getMessage());
            $this->fail($e);
        }
    }

    public function failed(Throwable $exception): void
    {
        // Вызывается при исчерпании всех попыток ИЛИ при явном $this->fail().
        // markDropped идемпотентен — безопасно вызывать даже если уже "dropped".
        Log::error('ProcessNotificationJob: все попытки исчерпаны, отбрасываем', [
            'notification_id' => $this->notificationId,
            'error'           => $exception->getMessage(),
        ]);

        app(NotificationRepository::class)->markDropped(
            $this->notificationId,
            'Доставка не выполнена после всех попыток: ' . $exception->getMessage(),
        );
    }
}
