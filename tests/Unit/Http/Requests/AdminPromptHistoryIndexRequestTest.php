<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Requests;

use App\Http\Requests\Admin\AdminPromptHistoryIndexRequest;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminPromptHistoryIndexRequestTest extends TestCase
{
    public function test_authorize_returns_true(): void
    {
        $this->assertTrue((new AdminPromptHistoryIndexRequest)->authorize());
    }

    public function test_rules_require_key_with_regex(): void
    {
        $rules = (new AdminPromptHistoryIndexRequest)->rules();

        $this->assertCount(3, $rules['key']);
        $this->assertSame('required', $rules['key'][0]);
        $this->assertSame('string', $rules['key'][1]);
        $this->assertSame('regex:/^(ai\.global_system_prompt|course\.\d+\.ai_course_prompt)$/', $rules['key'][2]);
    }

    public function test_messages_are_human_readable(): void
    {
        $messages = (new AdminPromptHistoryIndexRequest)->messages();

        $this->assertSame('Необходимо указать ключ промпта', $messages['key.required']);
        $this->assertSame('Недопустимый ключ промпта', $messages['key.regex']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function validKeyProvider(): array
    {
        return [
            'global system prompt' => ['ai.global_system_prompt'],
            'course prompt' => ['course.12.ai_course_prompt'],
            'single digit course id' => ['course.1.ai_course_prompt'],
        ];
    }

    #[DataProvider('validKeyProvider')]
    public function test_canonical_keys_pass(string $key): void
    {
        $validator = Validator::make(['key' => $key], (new AdminPromptHistoryIndexRequest)->rules());

        $this->assertTrue($validator->passes());
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidKeyProvider(): array
    {
        return [
            'missing key' => [[], 'key'],
            'empty key' => [['key' => ''], 'key'],
            'arbitrary string' => [['key' => 'some.random.key'], 'key'],
            'unknown ai key' => [['key' => 'ai.other_prompt'], 'key'],
            'course key without id' => [['key' => 'course.ai_course_prompt'], 'key'],
            'course key with non numeric id' => [['key' => 'course.abc.ai_course_prompt'], 'key'],
            'missing ai_course_prompt suffix' => [['key' => 'course.5.prompt'], 'key'],
            'key is an array' => [['key' => ['ai.global_system_prompt']], 'key'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[DataProvider('invalidKeyProvider')]
    public function test_invalid_key_fails(array $data, string $expectedErrorField): void
    {
        $validator = Validator::make($data, (new AdminPromptHistoryIndexRequest)->rules());

        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has($expectedErrorField));
    }
}
