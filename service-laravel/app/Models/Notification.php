<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Enums\NotificationType;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string                   $id
 * @property string                   $batch_id
 * @property string                   $recipient_id
 * @property NotificationChannel      $channel
 * @property NotificationType         $type
 * @property string                   $message
 * @property NotificationStatus       $status
 * @property string|null              $idempotency_key
 * @property int                      $retry_count
 * @property string|null              $error_message
 * @property \Carbon\Carbon           $created_at
 * @property \Carbon\Carbon           $updated_at
 * @property \Carbon\Carbon|null      $sent_at
 * @property \Carbon\Carbon|null      $delivered_at
 */
class Notification extends Model
{
    protected $table = 'notifications';

    /** UUID primary key — not auto-incrementing. */
    protected $keyType   = 'string';
    public    $incrementing = false;

    protected $fillable = [
        'id',
        'batch_id',
        'recipient_id',
        'channel',
        'type',
        'message',
        'status',
        'idempotency_key',
        'retry_count',
        'error_message',
        'sent_at',
        'delivered_at',
    ];

    protected $casts = [
        'channel'      => NotificationChannel::class,
        'type'         => NotificationType::class,
        'status'       => NotificationStatus::class,
        'retry_count'  => 'integer',
        'sent_at'      => 'datetime',
        'delivered_at' => 'datetime',
    ];
}
