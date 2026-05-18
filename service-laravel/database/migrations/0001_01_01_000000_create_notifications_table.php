<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            // UUID primary key — генерируется PostgreSQL через gen_random_uuid()
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));

            $table->uuid('batch_id');
            $table->string('recipient_id', 255);
            $table->string('channel', 10);          // 'sms' | 'email'
            $table->string('type', 20);             // 'transactional' | 'marketing'
            $table->text('message');
            $table->string('status', 20)->default('queued');
            $table->string('idempotency_key', 255)->nullable();
            $table->integer('retry_count')->default(0);
            $table->text('error_message')->nullable();

            // useCurrentOnUpdate() — аналог PostgreSQL-триггера на updated_at
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();

            // Базовые индексы
            $table->index('batch_id', 'ix_notifications_batch');
            $table->index('status', 'ix_notifications_status');
            $table->index(['recipient_id', 'created_at'], 'ix_notifications_recipient_created');

            // Составные индексы для фильтрации уведомлений подписчика.
            // Позволяют PostgreSQL выполнить Index Scan вместо Filter при запросах вида:
            //   WHERE recipient_id = ? AND status = ? ORDER BY created_at DESC
            $table->index(
                ['recipient_id', 'status', 'created_at'],
                'ix_notifications_recipient_status_created',
            );
            $table->index(
                ['recipient_id', 'channel', 'created_at'],
                'ix_notifications_recipient_channel_created',
            );
        });

        // NULLS NOT DISTINCT: два NULL-значения считаются одинаковыми —
        // защита от случайных дублей при idempotency_key IS NULL.
        // Laravel Blueprint->unique() создаёт стандартный UNIQUE без этой опции,
        // поэтому индекс объявляется через raw SQL (PostgreSQL 15+).
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX uq_notifications_idempotency_key
            ON notifications (idempotency_key)
            NULLS NOT DISTINCT
            WHERE idempotency_key IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
