<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Repositories\NotificationRepository;
use App\Services\NotificationPublisher;
use Illuminate\Console\Command;

/**
 * Восстанавливает уведомления, застрявшие в статусе 'queued'.
 *
 * Паттерн "publish-after-commit" не защищает от краша процесса
 * ПОСЛЕ коммита транзакции, но ДО завершения цикла публикации.
 * В этом случае строки остаются в БД со статусом 'queued' навсегда —
 * воркер их не увидит, так как они не попали в RabbitMQ.
 *
 * Команда находит такие строки (статус 'queued' + created_at старше N секунд)
 * и публикует их повторно. Нормальная публикация происходит почти мгновенно
 * после сохранения, поэтому задержка > N секунд — признак краша в цикле публикации.
 *
 * Запускается при старте app-контейнера (entrypoint.sh) и может быть
 * добавлена в Scheduler для периодического запуска.
 */
class RepublishStuckNotifications extends Command
{
    protected $signature = 'notifications:republish-stuck
                            {--older-than=60 : Минимальный возраст застрявшей записи в секундах}';

    protected $description = 'Republish notifications stuck in queued status after a crash';

    public function handle(
        NotificationRepository $repo,
        NotificationPublisher  $publisher,
    ): int {
        $olderThan = (int) $this->option('older-than');

        $stuck = $repo->findStuckQueued(olderThanSeconds: $olderThan);

        if ($stuck->isEmpty()) {
            $this->info('No stuck notifications found.');
            return self::SUCCESS;
        }

        foreach ($stuck as $notification) {
            $publisher->publish($notification);
        }

        $this->info("Republished {$stuck->count()} stuck notification(s).");

        return self::SUCCESS;
    }
}
