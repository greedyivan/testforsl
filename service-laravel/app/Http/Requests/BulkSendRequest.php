<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\NotificationChannel;
use App\Enums\NotificationType;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BulkSendRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Rule::in(Enum::values()) — единственный источник истины для допустимых значений.
            // При добавлении нового case в enum правило автоматически расширяется,
            // без необходимости обновлять строку вручную.
            'channel'         => ['required', 'string', Rule::in(NotificationChannel::values())],
            'type'            => ['required', 'string', Rule::in(NotificationType::values())],
            // Ограничение max защищает от DoS: один запрос не может создать
            // произвольное количество строк в БД и сообщений в брокере.
            'recipient_ids'   => ['required', 'array', 'min:1', 'max:1000'],
            'recipient_ids.*' => ['required', 'string', 'max:255', 'distinct'],
            // Ограничение max предотвращает memory-exhaustion при сериализации.
            'message'         => ['required', 'string', 'max:4096'],
            'idempotency_key' => ['required', 'string', 'max:255', 'regex:/^[\w\-]{1,255}$/'],
        ];
    }

    /**
     * Возвращает канал отправки в виде типизированного enum.
     * validated() гарантирует, что значение прошло Rule::in — from() безопасен.
     */
    public function channel(): NotificationChannel
    {
        return NotificationChannel::from($this->validated('channel'));
    }

    /**
     * Возвращает тип уведомления в виде типизированного enum.
     * validated() гарантирует, что значение прошло Rule::in — from() безопасен.
     */
    public function notificationType(): NotificationType
    {
        return NotificationType::from($this->validated('type'));
    }

    /**
     * Всегда возвращает 422 JSON — никогда не делает редирект.
     * Это чисто API-сервис; фронтенда для редиректа нет.
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new ValidationException($validator);
    }
}
