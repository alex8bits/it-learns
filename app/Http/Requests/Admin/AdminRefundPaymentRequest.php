<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class AdminRefundPaymentRequest extends FormRequest
{
    /**
     * The actual authorization check is performed in the controller via
     * `$this->authorize('refund', $payment)` (PaymentPolicy::refund — admin
     * only). Returning `true` here keeps FormRequest focused on input
     * validation.
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
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.string' => 'Причина возврата должна быть строкой',
            'reason.max' => 'Причина возврата не может быть длиннее 1000 символов',
        ];
    }
}
