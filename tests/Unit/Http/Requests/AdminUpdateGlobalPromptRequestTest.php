<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Requests;

use App\Http\Requests\Admin\AdminUpdateGlobalPromptRequest;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminUpdateGlobalPromptRequestTest extends TestCase
{
    public function test_authorize_returns_true(): void
    {
        $this->assertTrue((new AdminUpdateGlobalPromptRequest)->authorize());
    }

    public function test_rules_cover_body_and_comment(): void
    {
        $rules = (new AdminUpdateGlobalPromptRequest)->rules();

        $this->assertSame(['required', 'string', 'min:1', 'max:65535'], $rules['body']);
        $this->assertSame(['nullable', 'string', 'max:1000'], $rules['comment']);
    }

    public function test_messages_are_human_readable(): void
    {
        $messages = (new AdminUpdateGlobalPromptRequest)->messages();

        $this->assertSame('Текст промпта обязателен', $messages['body.required']);
        $this->assertSame('Текст промпта не может превышать 65535 символов', $messages['body.max']);
        $this->assertSame('Комментарий не может превышать 1000 символов', $messages['comment.max']);
    }

    public function test_valid_payload_passes(): void
    {
        $validator = Validator::make([
            'body' => 'Ты — ассистент учебной платформы.',
            'comment' => 'Первая редакция',
        ], (new AdminUpdateGlobalPromptRequest)->rules());

        $this->assertTrue($validator->passes());
    }

    public function test_payload_without_comment_passes(): void
    {
        $validator = Validator::make([
            'body' => 'Ты — ассистент учебной платформы.',
        ], (new AdminUpdateGlobalPromptRequest)->rules());

        $this->assertTrue($validator->passes());
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidPayloadProvider(): array
    {
        return [
            'missing body' => [[], 'body'],
            'empty body' => [['body' => ''], 'body'],
            'body is an array' => [['body' => ['x']], 'body'],
            'body over 65535 chars' => [['body' => str_repeat('а', 65536)], 'body'],
            'comment over 1000 chars' => [['body' => 'ok', 'comment' => str_repeat('а', 1001)], 'comment'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[DataProvider('invalidPayloadProvider')]
    public function test_invalid_payload_fails(array $data, string $expectedErrorField): void
    {
        $validator = Validator::make($data, (new AdminUpdateGlobalPromptRequest)->rules());

        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has($expectedErrorField));
    }
}
