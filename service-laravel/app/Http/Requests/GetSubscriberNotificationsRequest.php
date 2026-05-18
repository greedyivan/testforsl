<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class GetSubscriberNotificationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Фильтруем только по допустимым значениям enum —
            // некорректный статус вернёт 422, а не пустой массив.
            'status'  => ['nullable', 'string', Rule::in(NotificationStatus::values())],
            'channel' => ['nullable', 'string', Rule::in(NotificationChannel::values())],
            'limit'   => ['nullable', 'integer', 'min:1', 'max:200'],
            'offset'  => ['nullable', 'integer', 'min:0'],
        ];
    }

    /** Количество записей на страницу (1–200, по умолчанию 50). */
    public function limit(): int
    {
        return (int) ($this->validated('limit') ?? 50);
    }

    /** Смещение от начала выборки (по умолчанию 0). */
    public function offset(): int
    {
        return (int) ($this->validated('offset') ?? 0);
    }

    /** Всегда возвращаем 422 JSON — этот сервис не имеет web-фронтенда. */
    protected function failedValidation(Validator $validator): void
    {
        throw new ValidationException($validator);
    }
}
