<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Enums\NotificationType;
use App\Models\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class NotificationRepository
{
    /**
     * Bulk-insert one notification row per recipient and return all created models.
     *
     * @param  string[]  $recipientIds
     * @return Collection<int, Notification>
     */
    public function createMany(
        string $batchId,
        array $recipientIds,
        NotificationChannel $channel,
        NotificationType $type,
        string $message,
        string $idempotencyKey,
    ): Collection {
        $rows = [];
        $now  = now();

        foreach ($recipientIds as $recipientId) {
            $rows[] = [
                'id'              => (string) Str::uuid(),
                'batch_id'        => $batchId,
                'recipient_id'    => $recipientId,
                'channel'         => $channel->value,
                'type'            => $type->value,
                'message'         => $message,
                'status'          => NotificationStatus::Queued->value,
                'idempotency_key' => $idempotencyKey . ':' . $recipientId,
                'retry_count'     => 0,
                'created_at'      => $now,
                'updated_at'      => $now,
            ];
        }

        DB::table('notifications')->insert($rows);

        // Конструируем модели из уже имеющихся данных вместо второго SELECT.
        // Все значения (включая id, created_at) переданы явно, поэтому
        // DB-дефолты не нужны и дополнительный round-trip не требуется.
        return collect($rows)->map(function (array $row): Notification {
            $model = (new Notification())->fill($row);
            $model->exists = true;
            $model->wasRecentlyCreated = true;
            // Приводим enum-поля из строк к типизированным значениям,
            // как это делает Eloquent при загрузке из БД.
            $model->setRawAttributes($row, true);
            return $model;
        });
    }

    public function find(string $id): ?Notification
    {
        return Notification::find($id);
    }

    /**
     * Атомарно переводит статус queued|sent → sent.
     *
     * Возвращает true, если переход выполнен (запись была в ожидающем статусе).
     * Возвращает false, если уведомление уже в терминальном состоянии.
     *
     * Channel для выбора провайдера передаётся в ProcessNotificationJob при публикации,
     * поэтому дополнительный SELECT внутри этого метода не нужен.
     */
    public function tryClaimForProcessing(string $id): bool
    {
        return DB::table('notifications')
            ->where('id', $id)
            ->whereIn('status', NotificationStatus::pendingValues())
            ->update([
                'status'     => NotificationStatus::Sent->value,
                'sent_at'    => now(),
                'updated_at' => now(),
            ]) > 0;
    }

    public function markDelivered(string $id): void
    {
        DB::table('notifications')
            ->where('id', $id)
            ->update([
                'status'       => NotificationStatus::Delivered->value,
                'delivered_at' => now(),
                'updated_at'   => now(),
            ]);
    }

    public function markDropped(string $id, string $errorMessage): void
    {
        DB::table('notifications')
            ->where('id', $id)
            ->whereNotIn('status', [NotificationStatus::Dropped->value])
            ->update([
                'status'        => NotificationStatus::Dropped->value,
                'error_message' => $errorMessage,
                'updated_at'    => now(),
            ]);
    }

    public function incrementRetryCount(string $id): void
    {
        // Одна операция вместо двух: убран лишний SELECT для возврата значения,
        // которое ни один из вызывающих методов не использует.
        DB::table('notifications')
            ->where('id', $id)
            ->increment('retry_count', 1, ['updated_at' => now()]);
    }

    public function resetToQueued(string $id): void
    {
        DB::table('notifications')
            ->where('id', $id)
            ->whereIn('status', NotificationStatus::pendingValues())
            ->update([
                'status'     => NotificationStatus::Queued->value,
                'updated_at' => now(),
            ]);
    }

    /**
     * Находит уведомления, застрявшие в статусе 'queued' дольше заданного порога.
     *
     * Нормальная публикация в RabbitMQ происходит почти мгновенно после коммита.
     * Если запись в 'queued' уже N секунд — это признак краша между коммитом
     * транзакции и публикацией (publish-after-commit gap).
     *
     * @return Collection<int, Notification>
     */
    public function findStuckQueued(int $olderThanSeconds = 60): Collection
    {
        return Notification::where('status', NotificationStatus::Queued->value)
            ->where('created_at', '<', now()->subSeconds($olderThanSeconds))
            ->get();
    }

    /**
     * List notifications for a subscriber with optional status/channel filters and pagination.
     *
     * `total` — полное число записей по фильтрам (до применения limit/offset),
     * нужен клиентам для вычисления числа страниц.
     * `items` — срез выборки размером $limit начиная с $offset.
     *
     * @return array{total: int, items: Collection<int, Notification>}
     */
    public function listForSubscriber(
        string $recipientId,
        ?string $status = null,
        ?string $channel = null,
        int $limit = 50,
        int $offset = 0,
    ): array {
        $query = Notification::where('recipient_id', $recipientId);

        if ($status !== null) {
            $query->where('status', $status);
        }

        if ($channel !== null) {
            $query->where('channel', $channel);
        }

        // Считаем total до применения limit/offset.
        // Eloquent выполняет count() отдельным SELECT COUNT(*) — builder не мутирует.
        $total = $query->count();
        $items = $query->orderBy('created_at', 'desc')->limit($limit)->offset($offset)->get();

        return ['total' => $total, 'items' => $items];
    }
}
