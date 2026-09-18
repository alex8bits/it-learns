<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Requests;

use App\Enums\PracticeRuntime;
use App\Http\Requests\Admin\AdminPracticeTaskRequest;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminPracticeTaskRequestTest extends TestCase
{
    public function test_authorize_returns_true(): void
    {
        $this->assertTrue((new AdminPracticeTaskRequest)->authorize());
    }

    public function test_rules_cover_task_fields(): void
    {
        $rules = (new AdminPracticeTaskRequest)->rules();

        $this->assertSame(['required', 'string', 'max:8192'], $rules['statement']);
        $this->assertSame(['required', 'string', 'max:8192'], $rules['expected_result_text']);
        $this->assertSame(['required', 'string', 'max:65535'], $rules['expected_rows']);
        $this->assertSame(['nullable', 'string', 'max:65535'], $rules['seed_sql']);
        $this->assertSame(['nullable', 'integer', 'min:1'], $rules['order']);
        $this->assertSame(['nullable', 'boolean'], $rules['is_published']);
        $this->assertSame('required', $rules['runtime'][0]);
        $this->assertSame('string', $rules['runtime'][1]);
        $this->assertEquals(new Enum(PracticeRuntime::class), $rules['runtime'][2]);
    }

    public function test_messages_are_human_readable(): void
    {
        $messages = (new AdminPracticeTaskRequest)->messages();

        $this->assertSame('Текст практического задания обязателен', $messages['statement.required']);
        $this->assertSame('Описание ожидаемого результата обязательно', $messages['expected_result_text.required']);
        $this->assertSame('Эталонные строки обязательны', $messages['expected_rows.required']);
        $this->assertSame('Скрипт наполнения не может превышать 65535 символов', $messages['seed_sql.max']);
        $this->assertSame('Порядковый номер задания должен быть целым числом', $messages['order.integer']);
        $this->assertSame('Порядковый номер задания должен быть не меньше 1', $messages['order.min']);
        $this->assertSame('Признак публикации задания должен быть булевым значением', $messages['is_published.boolean']);
        $this->assertSame('Рантайм задания обязателен', $messages['runtime.required']);
        $this->assertSame('Рантайм задания должен быть строкой', $messages['runtime.string']);
        $this->assertSame(
            'Рантайм задания должен быть одним из поддерживаемых значений',
            $messages['runtime.Illuminate\Validation\Rules\Enum'],
        );
    }

    public function test_valid_payload_passes_and_validates(): void
    {
        $request = $this->makeRequest([
            'statement' => 'Выберите название и год каждой книги',
            'expected_result_text' => 'Две строки, отсортированные по году',
            'expected_rows' => '[{"id": 1, "title": "SQL Basics", "year": 2020}, {"id": 2, "title": "Advanced SQL", "year": 2021}]',
            'seed_sql' => 'CREATE TABLE books (id INTEGER PRIMARY KEY, title TEXT, year INTEGER);',
            'order' => 2,
            'is_published' => true,
            'runtime' => 'sqlite',
        ]);

        $request->validateResolved();

        $validated = $request->validated();
        $this->assertSame('Выберите название и год каждой книги', $validated['statement']);
        $this->assertSame('Две строки, отсортированные по году', $validated['expected_result_text']);
        $this->assertSame('[{"id": 1, "title": "SQL Basics", "year": 2020}, {"id": 2, "title": "Advanced SQL", "year": 2021}]', $validated['expected_rows']);
        $this->assertSame('CREATE TABLE books (id INTEGER PRIMARY KEY, title TEXT, year INTEGER);', $validated['seed_sql'] ?? null);
        $this->assertSame(2, $validated['order'] ?? null);
        $this->assertTrue($validated['is_published'] ?? null);
        $this->assertSame('sqlite', $validated['runtime']);

        $this->assertSame([
            ['id' => 1, 'title' => 'SQL Basics', 'year' => 2020],
            ['id' => 2, 'title' => 'Advanced SQL', 'year' => 2021],
        ], $request->decodedRows());
    }

    /**
     * Every enum case is accepted: the admin select sends the string
     * value, the driver mismatch is a provision-time concern, not a
     * validation one.
     *
     * @return array<string, list<string>>
     */
    public static function runtimeValueProvider(): array
    {
        return [
            'sqlite' => ['sqlite'],
            'mysql' => ['mysql'],
            'postgres' => ['postgres'],
        ];
    }

    #[DataProvider('runtimeValueProvider')]
    public function test_every_runtime_enum_value_passes(string $runtime): void
    {
        $request = $this->makeRequest([
            'statement' => 'Задача',
            'expected_result_text' => 'Строки',
            'expected_rows' => '[{"id": 1}]',
            'runtime' => $runtime,
        ]);

        $request->validateResolved();

        $this->assertSame($runtime, $request->validated()['runtime']);
    }

    public function test_payload_without_optional_fields_passes(): void
    {
        $request = $this->makeRequest([
            'statement' => 'Задача без опций',
            'expected_result_text' => 'Любые строки',
            'expected_rows' => '[{"id": 1, "title": "SQL Basics"}]',
            'runtime' => 'sqlite',
        ]);

        $request->validateResolved();

        $validated = $request->validated();
        $this->assertArrayNotHasKey('seed_sql', $validated);
        $this->assertArrayNotHasKey('order', $validated);
        $this->assertArrayNotHasKey('is_published', $validated);
    }

    public function test_null_values_are_accepted_for_nullable_fields(): void
    {
        $request = $this->makeRequest([
            'statement' => 'Задача',
            'expected_result_text' => 'Строки',
            'expected_rows' => '[{"id": 1}]',
            'seed_sql' => null,
            'runtime' => 'mysql',
        ]);

        $request->validateResolved();

        $validated = $request->validated();
        $this->assertNull($validated['seed_sql'] ?? null);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string, 2: string}>
     */
    public static function invalidPayloadProvider(): array
    {
        return [
            'missing statement' => [
                ['expected_result_text' => 'Строки', 'expected_rows' => '[{"id": 1}]'],
                'statement',
                'Текст практического задания обязателен',
            ],
            'missing expected result text' => [
                ['statement' => 'Задача', 'expected_rows' => '[{"id": 1}]'],
                'expected_result_text',
                'Описание ожидаемого результата обязательно',
            ],
            'missing expected rows' => [
                ['statement' => 'Задача', 'expected_result_text' => 'Строки'],
                'expected_rows',
                'Эталонные строки обязательны',
            ],
            'expected rows is not json' => [
                ['statement' => 'Задача', 'expected_result_text' => 'Строки', 'expected_rows' => '{"id": 1,]'],
                'expected_rows',
                'Эталонные строки должны быть корректным JSON',
            ],
            'expected rows is json null' => [
                ['statement' => 'Задача', 'expected_result_text' => 'Строки', 'expected_rows' => 'null'],
                'expected_rows',
                'Эталонные строки должны быть корректным JSON',
            ],
            'empty row list' => [
                ['statement' => 'Задача', 'expected_result_text' => 'Строки', 'expected_rows' => '[]'],
                'expected_rows',
                'Эталонные строки должны быть непустым JSON-массивом строк-объектов',
            ],
            'top level object instead of list' => [
                ['statement' => 'Задача', 'expected_result_text' => 'Строки', 'expected_rows' => '{"id": 1}'],
                'expected_rows',
                'Эталонные строки должны быть непустым JSON-массивом строк-объектов',
            ],
            'scalar instead of list' => [
                ['statement' => 'Задача', 'expected_result_text' => 'Строки', 'expected_rows' => '"rows"'],
                'expected_rows',
                'Эталонные строки должны быть непустым JSON-массивом строк-объектов',
            ],
            'list of scalars' => [
                ['statement' => 'Задача', 'expected_result_text' => 'Строки', 'expected_rows' => '[1, 2]'],
                'expected_rows',
                'Каждая строка эталона должна быть JSON-объектом',
            ],
            'list of lists' => [
                ['statement' => 'Задача', 'expected_result_text' => 'Строки', 'expected_rows' => '[[1, 2]]'],
                'expected_rows',
                'Каждая строка эталона должна быть JSON-объектом',
            ],
            'empty object row' => [
                ['statement' => 'Задача', 'expected_result_text' => 'Строки', 'expected_rows' => '[{}]'],
                'expected_rows',
                'Каждая строка эталона должна быть JSON-объектом',
            ],
            'nested arrays in values' => [
                ['statement' => 'Задача', 'expected_result_text' => 'Строки', 'expected_rows' => '[{"id": [1, 2]}]'],
                'expected_rows',
                'Значения строк эталона должны быть скалярными или null',
            ],
            'nested objects in values' => [
                ['statement' => 'Задача', 'expected_result_text' => 'Строки', 'expected_rows' => '[{"id": {"a": 1}}]'],
                'expected_rows',
                'Значения строк эталона должны быть скалярными или null',
            ],
            'inconsistent keys across rows' => [
                ['statement' => 'Задача', 'expected_result_text' => 'Строки', 'expected_rows' => '[{"id": 1}, {"title": "a"}]'],
                'expected_rows',
                'Набор ключей должен быть одинаковым во всех строках эталона',
            ],
            'missing key in second row' => [
                ['statement' => 'Задача', 'expected_result_text' => 'Строки', 'expected_rows' => '[{"id": 1, "title": "a"}, {"id": 2}]'],
                'expected_rows',
                'Набор ключей должен быть одинаковым во всех строках эталона',
            ],
            'order below one' => [
                ['statement' => 'Задача', 'expected_result_text' => 'Строки', 'expected_rows' => '[{"id": 1}]', 'order' => 0],
                'order',
                'Порядковый номер задания должен быть не меньше 1',
            ],
            'non-integer order' => [
                ['statement' => 'Задача', 'expected_result_text' => 'Строки', 'expected_rows' => '[{"id": 1}]', 'order' => 'first'],
                'order',
                'Порядковый номер задания должен быть целым числом',
            ],
            'non-boolean publish flag' => [
                ['statement' => 'Задача', 'expected_result_text' => 'Строки', 'expected_rows' => '[{"id": 1}]', 'is_published' => 'да'],
                'is_published',
                'Признак публикации задания должен быть булевым значением',
            ],
            'missing runtime' => [
                ['statement' => 'Задача', 'expected_result_text' => 'Строки', 'expected_rows' => '[{"id": 1}]'],
                'runtime',
                'Рантайм задания обязателен',
            ],
            'unsupported runtime' => [
                ['statement' => 'Задача', 'expected_result_text' => 'Строки', 'expected_rows' => '[{"id": 1}]', 'runtime' => 'oracle'],
                'runtime',
                'Рантайм задания должен быть одним из поддерживаемых значений',
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
     * layer would (rules + messages + withValidator hooks), so the
     * JSON structure checks of `expected_rows` are exercised too.
     *
     * @param  array<string, mixed>  $data
     */
    private function makeRequest(array $data): AdminPracticeTaskRequest
    {
        $request = new AdminPracticeTaskRequest;
        $request->setContainer($this->app);
        // The redirector is normally injected by the HTTP layer; binding
        // it here lets `validateResolved()` build its redirect on failure
        // exactly like it would inside a real request cycle.
        $request->setRedirector($this->app['redirect']);
        $request->replace($data);

        return $request;
    }
}
