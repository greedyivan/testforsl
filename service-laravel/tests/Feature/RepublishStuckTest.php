<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Support\IntegrationTestCase;

/**
 * Проверяет, что republisher подхватывает уведомления, застрявшие в статусе 'queued'
 * из-за publish-after-commit gap, и доставляет их.
 *
 * Сценарий: вставляем строку напрямую в БД с заведомо старым created_at, минуя RabbitMQ.
 * Republisher (интервал 10 с в тестовой среде) обнаруживает её и публикует в очередь;
 * воркер затем доставляет уведомление.
 */
class RepublishStuckTest extends IntegrationTestCase
{
    public function test_stuck_notification_gets_republished_and_delivered(): void
    {
        $recipient      = $this->uniqueRecipient();
        $notificationId = $this->makeUuid();
        $batchId        = $this->makeUuid();

        // Открываем прямое PDO-соединение — Laravel app в тестовом процессе не загружается.
        $pdo = $this->makePdo();

        $idempotencyKey = "stuck-ik:{$recipient}";
        // Timestamp из далёкого прошлого — гарантированно старше порога --older-than=60.
        $oldTime = '2000-01-01 00:00:00+00';

        // Laravel хранит значения enum в нижнем регистре (Eloquent вызывает $enum->value).
        $pdo->prepare(
            <<<SQL
            INSERT INTO notifications (
                id, batch_id, recipient_id, channel, type, message, status,
                idempotency_key, retry_count, created_at, updated_at
            ) VALUES (?, ?, ?, 'sms', 'marketing', 'Stuck republish test', 'queued',
                      ?, 0, ?, ?)
            SQL
        )->execute([$notificationId, $batchId, $recipient, $idempotencyKey, $oldTime, $oldTime]);

        // Republisher запускается каждые 10 с; даём 60 с на полный цикл.
        $result = $this->waitForStatus($recipient, $notificationId, 'delivered', timeoutSeconds: 60);
        $this->assertSame('delivered', $result['status']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** Генерирует UUID v4 без внешних зависимостей. */
    private function makeUuid(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // version 4
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // variant
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Открыть PDO-соединение через DATABASE_URL из окружения.
     * Перед парсингом обрезает SQLAlchemy-префикс (например "postgresql+asyncpg://").
     */
    private function makePdo(): \PDO
    {
        $rawUrl = (string) (getenv('DATABASE_URL') ?: 'postgresql://postgres:postgres@postgres:5432/notifications_test');

        // Убираем SQLAlchemy-префикс драйвера: "postgresql+asyncpg://" → "postgresql://"
        $url = preg_replace('#\+[^:]+://#', '://', $rawUrl);
        // Убираем схему: "postgresql://" → "postgres:postgres@postgres:5432/notifications_test"
        $url = preg_replace('#^[a-z]+://#', '', $url);

        // Parse "user:pass@host:port/dbname"
        preg_match('#^([^:]+):([^@]+)@([^:]+):(\d+)/(.+)$#', $url, $m);
        [, $user, $pass, $host, $port, $dbname] = $m;

        return new \PDO(
            "pgsql:host={$host};port={$port};dbname={$dbname}",
            $user,
            $pass,
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        );
    }
}
