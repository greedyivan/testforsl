<?php

declare(strict_types=1);

namespace App\Enums;

enum NotificationChannel: string
{
    case Sms   = 'sms';
    case Email = 'email';

    /** Возвращает массив строковых значений для SQL WHERE IN и валидации. */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
