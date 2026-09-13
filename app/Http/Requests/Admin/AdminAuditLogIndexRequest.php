<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\AdminAuditAction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminAuditLogIndexRequest extends FormRequest
{
    /**
     * The actual authorization check is performed in the controller via
     * `$this->authorize('viewAny', AdminAuditLog::class)`. Returning `true`
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
            'action' => ['nullable', Rule::enum(AdminAuditAction::class)],
            'admin_id' => ['nullable', 'integer', 'min:1'],
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
            'action.Illuminate\Validation\Rules\Enum' => 'Недопустимое значение действия',
            'admin_id.integer' => 'Идентификатор администратора должен быть целым числом',
            'admin_id.min' => 'Идентификатор администратора должен быть не меньше 1',
            'date_from.date' => 'Дата «от» должна быть корректной датой',
            'date_to.date' => 'Дата «до» должна быть корректной датой',
            'date_to.after_or_equal' => 'Дата «до» не может быть раньше даты «от»',
        ];
    }
}
