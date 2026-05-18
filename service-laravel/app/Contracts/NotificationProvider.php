<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\Notification;

interface NotificationProvider
{
    /**
     * Attempt to deliver the notification via this channel.
     *
     * @throws \App\Exceptions\TemporaryProviderException  for transient failures (retryable)
     * @throws \App\Exceptions\PermanentProviderException  for permanent failures (no retry)
     */
    public function send(Notification $notification): void;
}
