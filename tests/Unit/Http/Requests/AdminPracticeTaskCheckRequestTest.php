<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Requests;

use App\Http\Requests\Admin\AdminPracticeTaskCheckRequest;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminPracticeTaskCheckRequestTest extends TestCase
{
    public function test_authorize_returns_true(): void
    {
        $this->assertTrue((new AdminPracticeTaskCheckRequest)->authorize());
    }

    public function test_rules_cover_the_check_fields(): void
    {
        $rules = (new AdminPracticeTaskCheckRequest)->rules();

        $this->assertSame(['required', 'string', 'max:65535'], $rules['code']);
        $this->assertSame(['nullable', 'string', 'max:65535'], $rules['seed_sql']);
        $this->assertSame(['nullable', 'string', 'max:65535'], $rules['expected_rows']);
    }

    public function test_messages_are_human_readable(): void
    {
        $messages = (new AdminPracticeTaskCheckRequest)->messages();

        $this->assertSame('Эталонный запрос обязателен', $messages['code.required']);
        $this->assertSame('Эталонный запрос должен быть строкой', $messages['code.string']);
        $this->assertSame('Эталонный запрос не может превышать 65535 символов', $messages['code.max']);
        $this->assertSame('Скрипт наполнения должен быть строкой', $messages['seed_sql.string']);
        $this->assertSame('Скрипт наполнения не может превышать 65535 символов', $messages['seed_sql.max']);
        $this->assertSame('Эталонные строки должны быть строкой в формате JSON', $messages['expected_rows.string']);
        $this->assertSame('Эталонные строки не могут превышать 65535 символов', $messages['expected_rows.max']);
    }

    public function test_valid_payload_passes_and_validates(): void
    {
        $request = $this->makeRequest([
            'code' => 'SELECT id, title, year FROM books',
            'expected_rows' => '[{"id": 1, "title": "SQL Basics", "year": 2020}, {"id": 2, "title": "Advanced SQL", "year": 2021}]',
            'seed_sql' => 'CREATE TABLE books (id INTEGER PRIMARY KEY, title TEXT, year INTEGER);',
        ]);

        $request->validateResolved();

        $validated = $request->validated();
        $this->assertSame('SELECT id, title, year FROM books', $validated['code']);
        $this->assertSame('[{"id": 1, "title": "SQL Basics", "year": 2020}, {"id": 2, "title": "Advanced SQL", "year": 2021}]', $validated['expected_rows'] ?? null);
        $this->assertSame('CREATE TABLE books (id INTEGER PRIMARY KEY, title TEXT, year INTEGER);', $validated['seed_sql'] ?? null);

        $this->assertSame([
            ['id' => 1, 'title' => 'SQL Basics', 'year' => 2020],
            ['id' => 2, 'title' => 'Advanced SQL', 'year' => 2021],
        ], $request->decodedRows());
    }

    public function test_payload_without_optional_fields_passes(): void
    {
        $request = $this->makeRequest(['code' => 'SELECT 1']);

        $request->validateResolved();

        $validated = $request->validated();
        $this->assertArrayNotHasKey('seed_sql', $validated);
        $this->assertArrayNotHasKey('expected_rows', $validated);
        // No reference result supplied — the check runs "rows only".
        $this->assertNull($request->decodedRows());
    }

    public function test_empty_expected_rows_is_accepted_and_decodes_to_null(): void
    {
        $request = $this->makeRequest(['code' => 'SELECT 1', 'expected_rows' => '']);

        $request->validateResolved();

        $this->assertSame('', $request->validated()['expected_rows'] ?? null);
        $this->assertNull($request->decodedRows());
    }

    public function test_null_values_are_accepted_for_nullable_fields(): void
    {
        $request = $this->makeRequest(['code' => 'SELECT 1', 'seed_sql' => null, 'expected_rows' => null]);

        $request->validateResolved();

        $validated = $request->validated();
        $this->assertNull($validated['seed_sql'] ?? null);
        $this->assertNull($validated['expected_rows'] ?? null);
        $this->assertNull($request->decodedRows());
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string, 2: string}>
     */
    public static function invalidPayloadProvider(): array
    {
        return [
            'missing code' => [
                [],
                'code',
                'Эталонный запрос обязателен',
            ],
            'expected rows is not json' => [
                ['code' => 'SELECT 1', 'expected_rows' => '{"id": 1,]'],
                'expected_rows',
                'Эталонные строки должны быть корректным JSON',
            ],
            'expected rows is json null' => [
                ['code' => 'SELECT 1', 'expected_rows' => 'null'],
                'expected_rows',
                'Эталонные строки должны быть корректным JSON',
            ],
            'empty row list' => [
                ['code' => 'SELECT 1', 'expected_rows' => '[]'],
                'expected_rows',
                'Эталонные строки должны быть непустым JSON-массивом строк-объектов',
            ],
            'top level object instead of list' => [
                ['code' => 'SELECT 1', 'expected_rows' => '{"id": 1}'],
                'expected_rows',
                'Эталонные строки должны быть непустым JSON-массивом строк-объектов',
            ],
            'scalar instead of list' => [
                ['code' => 'SELECT 1', 'expected_rows' => '"rows"'],
                'expected_rows',
                'Эталонные строки должны быть непустым JSON-массивом строк-объектов',
            ],
            'list of scalars' => [
                ['code' => 'SELECT 1', 'expected_rows' => '[1, 2]'],
                'expected_rows',
                'Каждая строка эталона должна быть JSON-объектом',
            ],
            'list of lists' => [
                ['code' => 'SELECT 1', 'expected_rows' => '[[1, 2]]'],
                'expected_rows',
                'Каждая строка эталона должна быть JSON-объектом',
            ],
            'empty object row' => [
                ['code' => 'SELECT 1', 'expected_rows' => '[{}]'],
                'expected_rows',
                'Каждая строка эталона должна быть JSON-объектом',
            ],
            'nested arrays in values' => [
                ['code' => 'SELECT 1', 'expected_rows' => '[{"id": [1, 2]}]'],
                'expected_rows',
                'Значения строк эталона должны быть скалярными или null',
            ],
            'nested objects in values' => [
                ['code' => 'SELECT 1', 'expected_rows' => '[{"id": {"a": 1}}]'],
                'expected_rows',
                'Значения строк эталона должны быть скалярными или null',
            ],
            'inconsistent keys across rows' => [
                ['code' => 'SELECT 1', 'expected_rows' => '[{"id": 1}, {"title": "a"}]'],
                'expected_rows',
                'Набор ключей должен быть одинаковым во всех строках эталона',
            ],
            'missing key in second row' => [
                ['code' => 'SELECT 1', 'expected_rows' => '[{"id": 1, "title": "a"}, {"id": 2}]'],
                'expected_rows',
                'Набор ключей должен быть одинаковым во всех строках эталона',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[DataProvider('invalidPayloadProvider')]
    public function test_invalid_payload_fails_with_the_expected_error(
        array $data,
        string $expectedErrorField,
        string $expectedMessage,
    ): void {
        try {
            $this->makeRequest($data)->validateResolved();

            $this->fail('ValidationException was not thrown.');
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            $this->assertArrayHasKey($expectedErrorField, $errors);
            $this->assertContains($expectedMessage, $errors[$expectedErrorField]);
        }
    }

    /**
     * Build a resolved FormRequest over the payload the same way the HTTP
     * layer would (rules + messages + withValidator hooks), so the JSON
     * structure checks of `expected_rows` are exercised too.
     *
     * @param  array<string, mixed>  $data
     */
    private function makeRequest(array $data): AdminPracticeTaskCheckRequest
    {
        $request = new AdminPracticeTaskCheckRequest;
        $request->setContainer($this->app);
        // The redirector is normally injected by the HTTP layer; binding
        // it here lets `validateResolved()` build its redirect on failure
        // exactly like it would inside a real request cycle.
        $request->setRedirector($this->app['redirect']);
        $request->replace($data);

        return $request;
    }
}
