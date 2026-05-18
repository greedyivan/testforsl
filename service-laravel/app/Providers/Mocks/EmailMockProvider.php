<?php

declare(strict_types=1);

namespace App\Providers\Mocks;

final class EmailMockProvider extends MockNotificationProvider
{
    protected function channel(): string
    {
        return 'email';
    }
}
