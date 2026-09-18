<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Requests;

use App\Http\Requests\Admin\AdminCoursePromptRequest;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminCoursePromptRequestTest extends TestCase
{
    public function test_authorize_returns_true(): void
    {
        $this->assertTrue((new AdminCoursePromptRequest)->authorize());
    }

    public function test_rules_mirror_global_prompt_request(): void
    {
        $rules = (new AdminCoursePromptRequest)->rules();

        $this->assertSame(['required', 'string', 'min:1', 'max:65535'], $rules['body']);
        $this->assertSame(['nullable', 'string', 'max:1000'], $rules['comment']);
    }

    public function test_messages_are_human_readable(): void
    {
        $messages = (new AdminCoursePromptRequest)->messages();

        $this->assertSame('Текст промпта обязателен', $messages['body.required']);
        $this->assertSame('Текст промпта не может быть пустым', $messages['body.min']);
        $this->assertSame('Текст промпта не может превышать 65535 символов', $messages['body.max']);
        $this->assertSame('Комментарий не может превышать 1000 символов', $messages['comment.max']);
    }

    public function test_valid_payload_passes(): void
    {
        $validator = Validator::make([
            'body' => 'Отвечай в контексте курса «Основы SQL».',
            'comment' => 'Первая правка',
        ], (new AdminCoursePromptRequest)->rules());

        $this->assertTrue($validator->passes());
    }

    public function test_payload_without_comment_passes(): void
    {
        $validator = Validator::make([
            'body' => 'Отвечай в контексте курса «Основы SQL».',
        ], (new AdminCoursePromptRequest)->rules());

        $this->assertTrue($validator->passes());
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidPayloadProvider(): array
    {
        return [
            'body missing' => [['comment' => 'Правка'], 'body'],
            'body is an empty string' => [['body' => ''], 'body'],
            'body longer than 65535 chars' => [['body' => str_repeat('а', 65536)], 'body'],
            'comment longer than 1000 chars' => [['body' => 'Промпт', 'comment' => str_repeat('а', 1001)], 'comment'],
            'comment is an array' => [['body' => 'Промпт', 'comment' => ['а', 'б']], 'comment'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[DataProvider('invalidPayloadProvider')]
    public function test_invalid_payload_fails(array $data, string $expectedErrorField): void
    {
        $validator = Validator::make($data, (new AdminCoursePromptRequest)->rules());

        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has($expectedErrorField));
    }
}
