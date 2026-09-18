<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;

class AdminTheoryTaskRequest extends FormRequest
{
    /**
     * The actual authorization check is performed in the controller via
     * `$this->authorize('update', $lesson->level->course)` so the ability
     * resolves through `CoursePolicy`. Returning `true` here keeps
     * FormRequest focused on input validation.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Shared by create and update: the same task fields are writable in
     * both operations.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'question' => ['required', 'string', 'max:65535'],
            'order' => ['nullable', 'integer', 'min:0'],
            'is_published' => ['nullable', 'boolean'],
            'options' => ['required', 'array', 'min:2', 'max:10'],
            'options.*.text' => ['required', 'string', 'max:2000'],
            'options.*.is_correct' => ['required', 'boolean'],
            'options.*.error_text' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Cross-field rules that plain rule bags cannot express: exactly one
     * correct option, and every incorrect option carries a non-blank
     * explanation.
     */
    public function withValidator(ValidatorContract $validator): void
    {
        $validator->after(function (ValidatorContract $validator): void {
            $options = $this->input('options');

            if (! is_array($options)) {
                return;
            }

            $correctCount = 0;

            foreach ($options as $index => $option) {
                $isCorrect = filter_var($option['is_correct'] ?? false, FILTER_VALIDATE_BOOLEAN);

                if ($isCorrect) {
                    $correctCount++;

                    continue;
                }

                if (trim((string) ($option['error_text'] ?? '')) === '') {
                    $validator->errors()->add("options.{$index}.error_text", 'Для неверного варианта обязательно пояснение');
                }
            }

            if ($correctCount !== 1) {
                $validator->errors()->add('options', 'Должен быть ровно один правильный вариант');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'question.required' => 'Вопрос теоретического задания обязателен',
            'question.string' => 'Вопрос теоретического задания должен быть строкой',
            'question.max' => 'Вопрос теоретического задания не может превышать 65535 символов',
            'order.integer' => 'Порядковый номер задания должен быть целым числом',
            'order.min' => 'Порядковый номер задания не может быть отрицательным',
            'is_published.boolean' => 'Признак публикации задания должен быть булевым значением',
            'options.required' => 'Нужны варианты ответа',
            'options.array' => 'Варианты ответа должны быть массивом',
            'options.min' => 'Должно быть минимум 2 варианта ответа',
            'options.max' => 'Допускается не более 10 вариантов ответа',
            'options.*.text.required' => 'Текст варианта обязателен',
            'options.*.text.string' => 'Текст варианта должен быть строкой',
            'options.*.text.max' => 'Текст варианта не может превышать 2000 символов',
            'options.*.is_correct.required' => 'Нужно указать, верный ли вариант',
            'options.*.is_correct.boolean' => 'Признак верного варианта должен быть булевым значением',
            'options.*.error_text.string' => 'Пояснение к варианту должно быть строкой',
            'options.*.error_text.max' => 'Пояснение к варианту не может превышать 2000 символов',
        ];
    }

    /**
     * Narrow the validated payload for the array-shape contracts of
     * `CreateTheoryTask`/`UpdateTheoryTask`.
     *
     * @return array{question: string, order?: int, is_published?: bool, options: list<array{text: string, is_correct: bool, error_text?: string|null}>}
     */
    public function validated($key = null, $default = null)
    {
        return parent::validated($key, $default);
    }
}
