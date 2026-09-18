<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\PracticeRuntime;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminPracticeTaskRequest extends FormRequest
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
     * both operations. `expected_rows` arrives as a JSON string from the
     * admin textarea (structural validation lives in `withValidator`);
     * `expected_hash` is never accepted from the client — the server
     * derives it from the rows inside the Action. `runtime` picks the
     * per-task execution engine (Stage 10): the driver mismatch (e.g. a
     * mysql task on the local-sqlite installation) fails loudly at
     * provision time — a content configuration error, not a validation
     * concern.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'statement' => ['required', 'string', 'max:8192'],
            'expected_result_text' => ['required', 'string', 'max:8192'],
            'expected_rows' => ['required', 'string', 'max:65535'],
            'seed_sql' => ['nullable', 'string', 'max:65535'],
            'order' => ['nullable', 'integer', 'min:1'],
            'is_published' => ['nullable', 'boolean'],
            'runtime' => ['required', 'string', Rule::enum(PracticeRuntime::class)],
        ];
    }

    /**
     * Cross-field rules that plain rule bags cannot express: the JSON
     * payload of `expected_rows` must decode into a non-empty list of
     * non-empty assoc rows whose values are scalars or null and whose
     * key sets are consistent across rows — the exact shape
     * `CanonicalResultSerializer::hash()` consumes (columns are taken
     * from the first row).
     */
    public function withValidator(ValidatorContract $validator): void
    {
        $validator->after(function (ValidatorContract $validator): void {
            $raw = $this->input('expected_rows');

            if (! is_string($raw) || $raw === '') {
                return;
            }

            $decoded = json_decode($raw, true);

            if ($decoded === null) {
                $validator->errors()->add('expected_rows', 'Эталонные строки должны быть корректным JSON');

                return;
            }

            if (! is_array($decoded) || $decoded === [] || ! array_is_list($decoded)) {
                $validator->errors()->add('expected_rows', 'Эталонные строки должны быть непустым JSON-массивом строк-объектов');

                return;
            }

            /** @var list<string>|null $referenceKeys sorted key set of the first row */
            $referenceKeys = null;

            foreach ($decoded as $row) {
                if (! is_array($row) || $row === [] || array_is_list($row)) {
                    $validator->errors()->add('expected_rows', 'Каждая строка эталона должна быть JSON-объектом');

                    return;
                }

                foreach ($row as $value) {
                    if (! is_scalar($value) && $value !== null) {
                        $validator->errors()->add('expected_rows', 'Значения строк эталона должны быть скалярными или null');

                        return;
                    }
                }

                $keys = array_keys($row);
                sort($keys, SORT_STRING);

                if ($referenceKeys === null) {
                    $referenceKeys = $keys;

                    continue;
                }

                if ($keys !== $referenceKeys) {
                    $validator->errors()->add('expected_rows', 'Набор ключей должен быть одинаковым во всех строках эталона');

                    return;
                }
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'statement.required' => 'Текст практического задания обязателен',
            'statement.string' => 'Текст практического задания должен быть строкой',
            'statement.max' => 'Текст практического задания не может превышать 8192 символов',
            'expected_result_text.required' => 'Описание ожидаемого результата обязательно',
            'expected_result_text.string' => 'Описание ожидаемого результата должно быть строкой',
            'expected_result_text.max' => 'Описание ожидаемого результата не может превышать 8192 символов',
            'expected_rows.required' => 'Эталонные строки обязательны',
            'expected_rows.string' => 'Эталонные строки должны быть строкой в формате JSON',
            'expected_rows.max' => 'Эталонные строки не могут превышать 65535 символов',
            'seed_sql.string' => 'Скрипт наполнения должен быть строкой',
            'seed_sql.max' => 'Скрипт наполнения не может превышать 65535 символов',
            'order.integer' => 'Порядковый номер задания должен быть целым числом',
            'order.min' => 'Порядковый номер задания должен быть не меньше 1',
            'is_published.boolean' => 'Признак публикации задания должен быть булевым значением',
            'runtime.required' => 'Рантайм задания обязателен',
            'runtime.string' => 'Рантайм задания должен быть строкой',
            'runtime.Illuminate\Validation\Rules\Enum' => 'Рантайм задания должен быть одним из поддерживаемых значений',
        ];
    }

    /**
     * Narrow the validated payload for the array-shape contracts of
     * `CreatePracticeTask`/`UpdatePracticeTask`.
     *
     * @return array{statement: string, expected_result_text: string, expected_rows: string, runtime: string, seed_sql?: string|null, order?: int|null, is_published?: bool}
     */
    public function validated($key = null, $default = null)
    {
        return parent::validated($key, $default);
    }

    /**
     * Decode the validated `expected_rows` JSON string into the row list
     * consumed by `CanonicalResultSerializer`.
     *
     * @return list<array<string, mixed>>
     */
    public function decodedRows(): array
    {
        $attributes = $this->validated();

        /** @var string $raw */
        $raw = $attributes['expected_rows'] ?? '';
        /** @var mixed $decoded */
        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            return [];
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = array_values(array_filter($decoded, 'is_array'));

        return $rows;
    }
}
