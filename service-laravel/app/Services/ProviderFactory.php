<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\NotificationProvider;
use App\Enums\NotificationChannel;
use App\Providers\Mocks\EmailMockProvider;
use App\Providers\Mocks\SmsMockProvider;
use Predis\Client as Redis;

final class ProviderFactory
{
    /** @var array<string, NotificationProvider> Кэш экземпляров провайдеров. */
    private array $cache = [];

    public function __construct(private readonly Redis $redis) {}

    public function get(NotificationChannel $channel): NotificationProvider
    {
        // Оператор ??= гарантирует создание экземпляра только один раз
        // за время жизни воркера, что особенно важно для реальных провайдеров
        // с HTTP-клиентами и пулами соединений.
        return $this->cache[$channel->value] ??= match ($channel) {
            NotificationChannel::Sms   => new SmsMockProvider($this->redis),
            NotificationChannel::Email => new EmailMockProvider($this->redis),
            // PHP бросает UnhandledMatchError для необработанного case enum —
            // убираем явный default, чтобы компилятор помогал отслеживать
            // добавление нового канала без регистрации провайдера.
        };
    }
}
