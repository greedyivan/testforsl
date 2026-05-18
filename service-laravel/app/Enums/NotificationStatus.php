<?php

declare(strict_types=1);

namespace App\Enums;

enum NotificationStatus: string
{
    case Queued    = 'queued';
    case Sent      = 'sent';
    case Delivered = 'delivered';
    case Dropped   = 'dropped';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Delivered, self::Dropped => true,
            default                        => false,
        };
    }

    public function isPending(): bool
    {
        return match ($this) {
            self::Queued, self::Sent => true,
            default                  => false,
        };
    }

    /** Возвращает массив строковых значений для SQL WHERE IN и валидации. */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** Значения "ожидающих" статусов для SQL WHERE IN. */
    public static function pendingValues(): array
    {
        return array_column(
            array_filter(self::cases(), fn (self $s) => $s->isPending()),
            'value',
        );
    }
}
