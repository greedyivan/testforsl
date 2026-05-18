<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Единый маппинг модели Notification → JSON для всех эндпоинтов API.
 *
 * Использовать вместо ручного array_map в контроллерах и сервисах.
 * При добавлении нового поля достаточно изменить только этот класс.
 *
 * @mixin \App\Models\Notification
 */
class NotificationResource extends JsonResource
{
    /**
     * Отключаем обёртку {"data": ...}, чтобы ресурс встраивался
     * напрямую в массив ответа без лишнего уровня вложенности.
     */
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'notification_id' => $this->id,
            'batch_id'        => $this->batch_id,
            'recipient_id'    => $this->recipient_id,
            'channel'         => $this->channel->value,
            'type'            => $this->type->value,
            'message'         => $this->message,
            'status'          => $this->status->value,
            'retry_count'     => $this->retry_count,
            'error_message'   => $this->error_message,
            'created_at'      => $this->created_at?->toIso8601String(),
            'updated_at'      => $this->updated_at?->toIso8601String(),
            'sent_at'         => $this->sent_at?->toIso8601String(),
            'delivered_at'    => $this->delivered_at?->toIso8601String(),
        ];
    }
}
