<?php

declare(strict_types=1);

namespace App\Providers\Mocks;

final class SmsMockProvider extends MockNotificationProvider
{
    protected function channel(): string
    {
        return 'sms';
    }
}
