<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\PaymentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminPaymentIndexRequest extends FormRequest
{
    /**
     * The actual authorization check is performed in the controller via
     * `$this->authorize('viewAny', Payment::class)`. Returning `true`
     * here keeps FormRequest focused on input validation.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::enum(PaymentStatus::class)],
            'email' => ['nullable', 'string', 'max:255'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.Illuminate\Validation\Rules\Enum' => 'Недопустимое значение статуса',
            'email.string' => 'Email должен быть строкой',
            'email.max' => 'Email не может быть длиннее 255 символов',
            'date_from.date' => 'Дата «от» должна быть корректной датой',
            'date_to.date' => 'Дата «до» должна быть корректной датой',
            'date_to.after_or_equal' => 'Дата «до» не может быть раньше даты «от»',
        ];
    }
}
