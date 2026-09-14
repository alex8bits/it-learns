<?php

declare(strict_types=1);

namespace App\Http\Requests\Subscription;

use Illuminate\Foundation\Http\FormRequest;

class CheckoutReturnRequest extends FormRequest
{
    /**
     * The route sits behind `auth:web` (see routes/web.php), so the user is
     * already authenticated when validation runs. Mirroring the
     * `AdminUserIndexRequest` precedent, authorization stays in the routing
     * layer and the FormRequest focuses on input validation.
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
            'session' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'session.required' => 'Не указан идентификатор платёжной сессии',
            'session.string' => 'Идентификатор платёжной сессии должен быть строкой',
            'session.max' => 'Идентификатор платёжной сессии не может быть длиннее 255 символов',
        ];
    }
}
