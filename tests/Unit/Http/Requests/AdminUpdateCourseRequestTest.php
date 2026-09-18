<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Requests;

use App\Enums\CourseStatus;
use App\Http\Requests\Admin\AdminUpdateCourseRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Enum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminUpdateCourseRequestTest extends TestCase
{
    public function test_authorize_returns_true(): void
    {
        $this->assertTrue((new AdminUpdateCourseRequest)->authorize());
    }

    public function test_rules_mirror_create_rules(): void
    {
        $rules = (new AdminUpdateCourseRequest)->rules();

        $this->assertSame(['required', 'string', 'max:255'], $rules['title']);
        $this->assertSame(['required', 'string', 'max:65535'], $rules['description']);
        $this->assertSame('required', $rules['status'][0]);
        $this->assertInstanceOf(Enum::class, $rules['status'][1]);
        $this->assertSame(['nullable', 'integer', 'min:0', 'max:65535'], $rules['sort_order']);
        $this->assertSame(
            ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:min_width=200,min_height=200'],
            $rules['preview_image'],
        );
        // The course AI prompt is edited only via CoursePromptController —
        // the update request must not validate it.
        $this->assertArrayNotHasKey('ai_course_prompt', $rules);
    }

    public function test_messages_are_human_readable(): void
    {
        $messages = (new AdminUpdateCourseRequest)->messages();

        $this->assertSame('Название курса обязательно', $messages['title.required']);
        $this->assertSame('Статус курса обязателен', $messages['status.required']);
        $this->assertSame('Размер превью курса не может превышать 2 МБ', $messages['preview_image.max']);
        $this->assertSame('Порядок курса должен быть целым числом', $messages['sort_order.integer']);
        $this->assertSame('Порядок курса не может быть отрицательным', $messages['sort_order.min']);
        $this->assertSame('Порядок курса не может превышать 65535', $messages['sort_order.max']);
        $this->assertArrayNotHasKey('ai_course_prompt.max', $messages);
    }

    public function test_valid_payload_without_new_preview_passes(): void
    {
        $validator = Validator::make([
            'title' => 'Продвинутый SQL',
            'description' => 'Обновлённое описание',
            'status' => CourseStatus::Published->value,
        ], (new AdminUpdateCourseRequest)->rules());

        $this->assertTrue($validator->passes());
    }

    public function test_valid_payload_with_preview_replacement_passes(): void
    {
        $validator = Validator::make([
            'title' => 'Продвинутый SQL',
            'description' => 'Обновлённое описание',
            'status' => CourseStatus::Published->value,
            'preview_image' => UploadedFile::fake()->image('new-preview.png', 400, 300),
        ], (new AdminUpdateCourseRequest)->rules());

        $this->assertTrue($validator->passes());
    }

    public function test_valid_sort_order_passes(): void
    {
        $validator = Validator::make([
            'title' => 'Продвинутый SQL',
            'description' => 'Обновлённое описание',
            'status' => CourseStatus::Published->value,
            'sort_order' => 42,
        ], (new AdminUpdateCourseRequest)->rules());

        $this->assertTrue($validator->passes());
    }

    /**
     * @return array<string, array{0: mixed, 1: string}>
     */
    public static function invalidSortOrderProvider(): array
    {
        return [
            'negative sort order' => [-1, 'Порядок курса не может быть отрицательным'],
            'non-integer sort order' => ['abc', 'Порядок курса должен быть целым числом'],
            'sort order above 65535' => [65536, 'Порядок курса не может превышать 65535'],
        ];
    }

    #[DataProvider('invalidSortOrderProvider')]
    public function test_invalid_sort_order_fails_with_russian_message(mixed $value, string $message): void
    {
        $request = new AdminUpdateCourseRequest;
        $validator = Validator::make([
            'title' => 'Курс',
            'description' => 'Описание',
            'status' => CourseStatus::Draft->value,
            'sort_order' => $value,
        ], $request->rules(), $request->messages());

        $this->assertTrue($validator->fails());
        $this->assertSame($message, $validator->errors()->first('sort_order'));
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidPayloadProvider(): array
    {
        return [
            'description missing' => [[
                'title' => 'Курс',
                'status' => CourseStatus::Draft->value,
            ], 'description'],
            'unknown status value' => [[
                'title' => 'Курс',
                'description' => 'Описание',
                'status' => 'Hidden',
            ], 'status'],
            'non-image preview' => [[
                'title' => 'Курс',
                'description' => 'Описание',
                'status' => CourseStatus::Draft->value,
                'preview_image' => UploadedFile::fake()->create('notes.txt', 10),
            ], 'preview_image'],
            'preview smaller than 200x200' => [[
                'title' => 'Курс',
                'description' => 'Описание',
                'status' => CourseStatus::Draft->value,
                'preview_image' => UploadedFile::fake()->image('small.png', 150, 200),
            ], 'preview_image'],
            'title longer than 255 chars' => [[
                'title' => str_repeat('а', 256),
                'description' => 'Описание',
                'status' => CourseStatus::Draft->value,
            ], 'title'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[DataProvider('invalidPayloadProvider')]
    public function test_invalid_payload_fails(array $data, string $expectedErrorField): void
    {
        $validator = Validator::make($data, (new AdminUpdateCourseRequest)->rules());

        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has($expectedErrorField));
    }
}
