<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Requests;

use App\Http\Requests\Admin\AdminTheoryTaskRequest;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminTheoryTaskRequestTest extends TestCase
{
    /**
     * @return array<int, array{text: string, is_correct: bool, error_text?: string}>
     */
    public static function validOptions(): array
    {
        return [
            ['text' => 'SELECT * FROM users;', 'is_correct' => true],
            ['text' => 'SELECT users;', 'is_correct' => false, 'error_text' => 'Не тот синтаксис.'],
            ['text' => 'GET ALL users;', 'is_correct' => false, 'error_text' => 'Не команда SQL.'],
        ];
    }

    public function test_authorize_returns_true(): void
    {
        $this->assertTrue((new AdminTheoryTaskRequest)->authorize());
    }

    public function test_rules_cover_task_fields(): void
    {
        $rules = (new AdminTheoryTaskRequest)->rules();

        $this->assertSame(['required', 'string', 'max:65535'], $rules['question']);
        $this->assertSame(['nullable', 'integer', 'min:0'], $rules['order']);
        $this->assertSame(['nullable', 'boolean'], $rules['is_published']);
        $this->assertSame(['required', 'array', 'min:2', 'max:10'], $rules['options']);
        $this->assertSame(['required', 'string', 'max:2000'], $rules['options.*.text']);
        $this->assertSame(['required', 'boolean'], $rules['options.*.is_correct']);
        $this->assertSame(['nullable', 'string', 'max:2000'], $rules['options.*.error_text']);
    }

    public function test_messages_are_human_readable(): void
    {
        $messages = (new AdminTheoryTaskRequest)->messages();

        $this->assertSame('Вопрос теоретического задания обязателен', $messages['question.required']);
        $this->assertSame('Порядковый номер задания должен быть целым числом', $messages['order.integer']);
        $this->assertSame('Признак публикации задания должен быть булевым значением', $messages['is_published.boolean']);
        $this->assertSame('Должно быть минимум 2 варианта ответа', $messages['options.min']);
        $this->assertSame('Допускается не более 10 вариантов ответа', $messages['options.max']);
        $this->assertSame('Текст варианта обязателен', $messages['options.*.text.required']);
        $this->assertSame('Признак верного варианта должен быть булевым значением', $messages['options.*.is_correct.boolean']);
        $this->assertSame('Пояснение к варианту не может превышать 2000 символов', $messages['options.*.error_text.max']);
    }

    public function test_valid_payload_passes_and_validates(): void
    {
        $request = $this->makeRequest([
            'question' => 'Какой запрос выбирает все столбцы из таблицы users?',
            'order' => 2,
            'is_published' => true,
            'options' => self::validOptions(),
        ]);

        $request->validateResolved();

        $validated = $request->validated();
        $this->assertSame('Какой запрос выбирает все столбцы из таблицы users?', $validated['question']);
        $this->assertSame(2, $validated['order'] ?? null);
        $this->assertTrue($validated['is_published'] ?? null);
        $this->assertCount(3, $validated['options']);
        $this->assertSame('SELECT * FROM users;', $validated['options'][0]['text']);
        $this->assertTrue($validated['options'][0]['is_correct']);
        $this->assertArrayNotHasKey('error_text', $validated['options'][0]);
    }

    public function test_payload_without_optional_fields_passes(): void
    {
        $request = $this->makeRequest([
            'question' => 'Вопрос без опций',
            'options' => [
                ['text' => 'Верно', 'is_correct' => true],
                ['text' => 'Неверно', 'is_correct' => false, 'error_text' => 'Мимо.'],
            ],
        ]);

        $request->validateResolved();

        $validated = $request->validated();
        $this->assertArrayNotHasKey('order', $validated);
        $this->assertArrayNotHasKey('is_published', $validated);
    }

    public function test_more_than_ten_options_fail(): void
    {
        $options = [
            ['text' => 'Верно', 'is_correct' => true],
        ];
        for ($i = 0; $i < 10; $i++) {
            $options[] = ['text' => "Неверно {$i}", 'is_correct' => false, 'error_text' => "Пояснение {$i}."];
        }

        try {
            $this->makeRequest([
                'question' => 'Вопрос',
                'options' => $options,
            ])->validateResolved();

            $this->fail('ValidationException was not thrown.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('options', $exception->errors());
            $this->assertSame('Допускается не более 10 вариантов ответа', $exception->errors()['options'][0]);
        }
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string, 2: string}>
     */
    public static function invalidPayloadProvider(): array
    {
        $validOptions = [
            ['text' => 'Верно', 'is_correct' => true],
            ['text' => 'Неверно', 'is_correct' => false, 'error_text' => 'Мимо.'],
        ];

        return [
            'empty question' => [
                ['question' => '', 'options' => $validOptions],
                'question',
                'Вопрос теоретического задания обязателен',
            ],
            'single option' => [
                ['question' => 'Вопрос', 'options' => [['text' => 'Верно', 'is_correct' => true]]],
                'options',
                'Должно быть минимум 2 варианта ответа',
            ],
            'no correct option' => [
                [
                    'question' => 'Вопрос',
                    'options' => [
                        ['text' => 'Один', 'is_correct' => false, 'error_text' => 'Мимо.'],
                        ['text' => 'Два', 'is_correct' => false, 'error_text' => 'Мимо.'],
                    ],
                ],
                'options',
                'Должен быть ровно один правильный вариант',
            ],
            'two correct options' => [
                [
                    'question' => 'Вопрос',
                    'options' => [
                        ['text' => 'Один', 'is_correct' => true],
                        ['text' => 'Два', 'is_correct' => true],
                    ],
                ],
                'options',
                'Должен быть ровно один правильный вариант',
            ],
            'incorrect option without error text' => [
                [
                    'question' => 'Вопрос',
                    'options' => [
                        ['text' => 'Верно', 'is_correct' => true],
                        ['text' => 'Неверно', 'is_correct' => false],
                    ],
                ],
                'options.1.error_text',
                'Для неверного варианта обязательно пояснение',
            ],
            'incorrect option with blank error text' => [
                [
                    'question' => 'Вопрос',
                    'options' => [
                        ['text' => 'Верно', 'is_correct' => true],
                        ['text' => 'Неверно', 'is_correct' => false, 'error_text' => '   '],
                    ],
                ],
                'options.1.error_text',
                'Для неверного варианта обязательно пояснение',
            ],
            'option text missing' => [
                [
                    'question' => 'Вопрос',
                    'options' => [
                        ['text' => 'Верно', 'is_correct' => true],
                        ['is_correct' => false, 'error_text' => 'Мимо.'],
                    ],
                ],
                'options.1.text',
                'Текст варианта обязателен',
            ],
            'negative order' => [
                ['question' => 'Вопрос', 'order' => -1, 'options' => $validOptions],
                'order',
                'Порядковый номер задания не может быть отрицательным',
            ],
            'non-boolean correct flag' => [
                [
                    'question' => 'Вопрос',
                    'options' => [
                        ['text' => 'Верно', 'is_correct' => 'да'],
                        ['text' => 'Неверно', 'is_correct' => false, 'error_text' => 'Мимо.'],
                    ],
                ],
                'options.0.is_correct',
                'Признак верного варианта должен быть булевым значением',
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
     * cross-field checks are exercised too.
     *
     * @param  array<string, mixed>  $data
     */
    private function makeRequest(array $data): AdminTheoryTaskRequest
    {
        $request = new AdminTheoryTaskRequest;
        $request->setContainer($this->app);
        // The redirector is normally injected by the HTTP layer; binding
        // it here lets `validateResolved()` build its redirect on failure
        // exactly like it would inside a real request cycle.
        $request->setRedirector($this->app['redirect']);
        $request->replace($data);

        return $request;
    }
}
