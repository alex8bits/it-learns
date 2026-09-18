<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Input of the admin "check reference query" endpoint (POST
 * /admin/practice-tasks/check): the form data snapshot (seed script +
 * expected rows) plus the reference query the admin wants to run
 * against it. Unlike AdminPracticeTaskRequest nothing here is
 * persisted — the payload feeds a one-shot isolated environment run.
 */
class AdminPracticeTaskCheckRequest extends FormRequest
{
    /**
     * Authorization is the admin route group itself (web + auth +
     * role:Admin, bootstrap/app.php): the check is a stateless tool over
     * the form data with no course context to resolve through a Policy
     * (design decision, non-goal).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `code` is the reference query; `seed_sql` and `expected_rows` mirror
     * the task form fields so the admin checks exactly what is about to be
     * saved. `expected_rows` is optional here — a check without a reference
     * result just shows the actual rows.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:65535'],
            'seed_sql' => ['nullable', 'string', 'max:65535'],
            'expected_rows' => ['nullable', 'string', 'max:65535'],
        ];
    }

    /**
     * Cross-field rules that plain rule bags cannot express — the mirror
     * of AdminPracticeTaskRequest::withValidator(): the JSON payload of a
     * non-empty `expected_rows` must decode into a non-empty list of
     * non-empty assoc rows whose values are scalars or null and whose key
     * sets are consistent across rows (the exact shape
     * CanonicalResultSerializer::hash() consumes).
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
            'code.required' => 'Эталонный запрос обязателен',
            'code.string' => 'Эталонный запрос должен быть строкой',
            'code.max' => 'Эталонный запрос не может превышать 65535 символов',
            'seed_sql.string' => 'Скрипт наполнения должен быть строкой',
            'seed_sql.max' => 'Скрипт наполнения не может превышать 65535 символов',
            'expected_rows.string' => 'Эталонные строки должны быть строкой в формате JSON',
            'expected_rows.max' => 'Эталонные строки не могут превышать 65535 символов',
        ];
    }

    /**
     * @return array{code: string, seed_sql?: string|null, expected_rows?: string|null}
     */
    public function validated($key = null, $default = null)
    {
        return parent::validated($key, $default);
    }

    /**
     * Decode the validated `expected_rows` JSON string into the row list
     * consumed by VerifyPracticeTask — or null when the admin ran the
     * check without a reference result (an empty field is legitimate
     * here, unlike the store/update request where the rows are required).
     *
     * @return list<array<string, mixed>>|null
     */
    public function decodedRows(): ?array
    {
        $raw = $this->validated('expected_rows');

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        /** @var mixed $decoded */
        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            return null;
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = array_values(array_filter($decoded, 'is_array'));

        return $rows;
    }
}
