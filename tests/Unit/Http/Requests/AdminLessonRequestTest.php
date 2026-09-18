<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Requests;

use App\Http\Requests\Admin\AdminLessonRequest;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminLessonRequestTest extends TestCase
{
    public function test_authorize_returns_true(): void
    {
        $this->assertTrue((new AdminLessonRequest)->authorize());
    }

    public function test_rules_cover_lesson_fields(): void
    {
        $rules = (new AdminLessonRequest)->rules();

        $this->assertSame(['required', 'string', 'max:255'], $rules['title']);
        $this->assertSame(['nullable', 'string', 'max:65535'], $rules['material']);
        $this->assertSame(['nullable', 'boolean'], $rules['is_published']);
        $this->assertSame(['nullable', 'integer', 'min:0'], $rules['order']);
    }

    public function test_messages_are_human_readable(): void
    {
        $messages = (new AdminLessonRequest)->messages();

        $this->assertSame('Название урока обязательно', $messages['title.required']);
        $this->assertSame('Материал урока не может превышать 65535 символов', $messages['material.max']);
        $this->assertSame('Признак публикации урока должен быть булевым значением', $messages['is_published.boolean']);
        $this->assertSame('Порядковый номер урока должен быть целым числом', $messages['order.integer']);
    }

    public function test_valid_payload_passes(): void
    {
        $validator = Validator::make([
            'title' => 'SELECT и фильтрация',
            'material' => 'Материал урока про выборку данных.',
            'is_published' => true,
            'order' => 1,
        ], (new AdminLessonRequest)->rules());

        $this->assertTrue($validator->passes());
    }

    public function test_payload_without_optional_fields_passes(): void
    {
        $validator = Validator::make([
            'title' => 'SELECT и фильтрация',
        ], (new AdminLessonRequest)->rules());

        $this->assertTrue($validator->passes());
    }

    public function test_falsy_publish_flag_passes(): void
    {
        $rules = (new AdminLessonRequest)->rules();

        $this->assertTrue(Validator::make(['title' => 'Урок', 'is_published' => false], $rules)->passes());
        $this->assertTrue(Validator::make(['title' => 'Урок', 'is_published' => 0], $rules)->passes());
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidPayloadProvider(): array
    {
        return [
            'title missing' => [['material' => 'Материал'], 'title'],
            'is_published is not a boolean' => [['title' => 'Урок', 'is_published' => 'да'], 'is_published'],
            'order is negative' => [['title' => 'Урок', 'order' => -1], 'order'],
            'order is not an integer' => [['title' => 'Урок', 'order' => 'first'], 'order'],
            'material longer than 65535 chars' => [
                ['title' => 'Урок', 'material' => str_repeat('а', 65536)],
                'material',
            ],
            'title longer than 255 chars' => [['title' => str_repeat('а', 256)], 'title'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[DataProvider('invalidPayloadProvider')]
    public function test_invalid_payload_fails(array $data, string $expectedErrorField): void
    {
        $validator = Validator::make($data, (new AdminLessonRequest)->rules());

        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has($expectedErrorField));
    }
}
