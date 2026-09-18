<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Requests;

use App\Http\Requests\Admin\AdminPromptPlaygroundRequest;
use App\Models\Course;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminPromptPlaygroundRequestTest extends TestCase
{
    public function test_authorize_returns_true(): void
    {
        $this->assertTrue((new AdminPromptPlaygroundRequest)->authorize());
    }

    public function test_rules_cover_course_id_and_user_message(): void
    {
        $rules = (new AdminPromptPlaygroundRequest)->rules();

        $this->assertCount(2, $rules);
        $this->assertSame(['required', 'string', 'min:1', 'max:65535'], $rules['user_message']);
        $this->assertContains('nullable', $rules['course_id']);
        $this->assertContains('integer', $rules['course_id']);
    }

    public function test_messages_are_human_readable(): void
    {
        $messages = (new AdminPromptPlaygroundRequest)->messages();

        $this->assertSame('Курс должен быть числом', $messages['course_id.integer']);
        $this->assertSame('Выбранный курс не найден', $messages['course_id.exists']);
        $this->assertSame('Сообщение для теста обязательно', $messages['user_message.required']);
        $this->assertSame('Сообщение должно быть строкой', $messages['user_message.string']);
        $this->assertSame('Сообщение не может превышать 65535 символов', $messages['user_message.max']);
    }

    public function test_valid_payload_without_course_passes(): void
    {
        $validator = Validator::make([
            'user_message' => 'Объясни JOIN в SQL',
        ], (new AdminPromptPlaygroundRequest)->rules());

        $this->assertTrue($validator->passes());
    }

    public function test_valid_payload_with_existing_course_passes(): void
    {
        $course = Course::factory()->create();

        $validator = Validator::make([
            'course_id' => $course->id,
            'user_message' => 'Объясни JOIN в SQL',
        ], (new AdminPromptPlaygroundRequest)->rules());

        $this->assertTrue($validator->passes());
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidPayloadProvider(): array
    {
        return [
            'missing message' => [['course_id' => null], 'user_message'],
            'empty message' => [['user_message' => ''], 'user_message'],
            'message is an array' => [['user_message' => ['x']], 'user_message'],
            'message over 65535 chars' => [['user_message' => str_repeat('а', 65536)], 'user_message'],
            'course id is not a number' => [['course_id' => 'abc', 'user_message' => 'ok'], 'course_id'],
            'course id does not exist' => [['course_id' => 999999, 'user_message' => 'ok'], 'course_id'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[DataProvider('invalidPayloadProvider')]
    public function test_invalid_payload_fails(array $data, string $expectedErrorField): void
    {
        // A real course must exist for the exists-rule branch; create one
        // so only the payload itself decides the outcome.
        Course::factory()->create();

        $validator = Validator::make($data, (new AdminPromptPlaygroundRequest)->rules());

        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has($expectedErrorField));
    }
}
