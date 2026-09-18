<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Requests;

use App\Http\Requests\SubmitPracticeTaskRequest;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SubmitPracticeTaskRequestTest extends TestCase
{
    public function test_authorize_returns_true(): void
    {
        $this->assertTrue((new SubmitPracticeTaskRequest)->authorize());
    }

    public function test_rules_cover_only_the_student_code(): void
    {
        $rules = (new SubmitPracticeTaskRequest)->rules();

        $this->assertSame(['code'], array_keys($rules));
        $this->assertSame(['required', 'string', 'max:65535'], $rules['code']);
    }

    public function test_messages_are_human_readable(): void
    {
        $messages = (new SubmitPracticeTaskRequest)->messages();

        $this->assertSame('Необходимо указать код решения', $messages['code.required']);
        $this->assertSame('Код решения должен быть строкой', $messages['code.string']);
        $this->assertSame('Код решения не может превышать 65535 символов', $messages['code.max']);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function validPayloadsProvider(): array
    {
        return [
            'code only' => [['code' => 'SELECT 1']],
            'full stub payload is ignored, code still valid' => [[
                'code' => 'SELECT n FROM t',
                'task_text' => 'Выберите единицу',
                'seed_script' => 'CREATE TABLE t (n INTEGER);',
                'expected_hash' => '2cf24dba5fb0a30e26e83b2ac5b9e29e1b161e5c1fa7425e73043362938b9824',
            ]],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[DataProvider('validPayloadsProvider')]
    public function test_valid_payloads_pass(array $data): void
    {
        $validator = Validator::make($data, (new SubmitPracticeTaskRequest)->rules());

        $this->assertTrue($validator->passes());
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidPayloadsProvider(): array
    {
        return [
            'code missing' => [['task_text' => 'Задание'], 'code'],
            'code over the size bound' => [['code' => str_repeat('a', 65536)], 'code'],
            'code is an array' => [['code' => ['SELECT 1']], 'code'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[DataProvider('invalidPayloadsProvider')]
    public function test_invalid_payloads_fail(array $data, string $expectedErrorField): void
    {
        $validator = Validator::make($data, (new SubmitPracticeTaskRequest)->rules());

        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has($expectedErrorField));
    }
}
