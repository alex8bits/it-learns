<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\CourseStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminCourseIndexRequest extends FormRequest
{
    /**
     * The actual authorization check is performed in the controller via
     * `$this->authorize('viewAny', Course::class)`. Returning `true` here
     * keeps FormRequest focused on input validation.
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
            'status' => ['nullable', Rule::enum(CourseStatus::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.Illuminate\Validation\Rules\Enum' => 'Недопустимое значение статуса курса',
        ];
    }
}
