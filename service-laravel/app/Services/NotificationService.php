<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\NotificationChannel;
use App\Enums\NotificationType;
use App\Repositories\NotificationRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class NotificationService
{
    public function __construct(
        private readonly NotificationRepository $repository,
        private readonly NotificationPublisher  $publisher,
        private readonly IdempotencyService     $idempotency,
    ) {}

    /**
     * Принять bulk-запрос, сохранить записи в БД и опубликовать задачи в RabbitMQ.
     *
     * Возвращает массив в формате BulkSendResource (ответ 202).
     * При дублирующемся idempotency_key возвращает закэшированный ответ без изменений.
     */
    public function bulkSend(
        string $idempotencyKey,
        array  $recipientIds,
        NotificationChannel $channel,
        NotificationType    $type,
        string $message,
    ): array {
        // ── Идемпотентность уровня 1: вернуть кэш, если ключ уже использовался ──
        $cached = $this->idempotency->getApiResponse($idempotencyKey);
        if ($cached !== null) {
            return $cached;
        }

        $batchId = (string) Str::uuid();

        // ── Сохранение, затем публикация (паттерн publish-after-commit) ──────
        // Все строки вставляются в одной транзакции — при любом нарушении
        // ограничений БД откат произойдёт до того, как мы затронем брокер.
        // DB::transaction() возвращает значение замыкания — используем это
        // вместо reference-переменной, чтобы тип был виден статическому анализатору.
        $notifications = DB::transaction(
            fn () => $this->repository->createMany(
                batchId:        $batchId,
                recipientIds:   $recipientIds,
                channel:        $channel,
                type:           $type,
                message:        $message,
                idempotencyKey: $idempotencyKey,
            )
        );

        // Публикация после коммита — воркер никогда не увидит notification_id,
        // который ещё не зафиксирован в БД.
        foreach ($notifications as $notification) {
            $this->publisher->publish($notification);
        }

        // Ответ для кэша строится как plain array (Redis хранит JSON).
        // Поля соответствуют NotificationResource — при изменении структуры
        // ответа обновить оба места.
        $response = [
            'batch_id'      => $batchId,
            'accepted'      => $notifications->count(),
            'notifications' => $notifications->map(fn ($n) => [
                'notification_id' => $n->id,
                'recipient_id'    => $n->recipient_id,
                'status'          => $n->status->value,
            ])->values()->all(),
        ];

        // Кэшируем ответ для последующих запросов с тем же ключом
        $this->idempotency->setApiResponse($idempotencyKey, $response);

        return $response;
    }
}
