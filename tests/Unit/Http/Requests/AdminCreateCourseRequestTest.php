<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Requests;

use App\Enums\CourseStatus;
use App\Http\Requests\Admin\AdminCreateCourseRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Enum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminCreateCourseRequestTest extends TestCase
{
    public function test_authorize_returns_true(): void
    {
        $this->assertTrue((new AdminCreateCourseRequest)->authorize());
    }

    public function test_rules_cover_course_fields_and_preview_file(): void
    {
        $rules = (new AdminCreateCourseRequest)->rules();

        $this->assertSame(['required', 'string', 'max:255'], $rules['title']);
        $this->assertSame(['required', 'string', 'max:65535'], $rules['description']);
        $this->assertCount(2, $rules['status']);
        $this->assertSame('required', $rules['status'][0]);
        $this->assertInstanceOf(Enum::class, $rules['status'][1]);
        $this->assertSame(['nullable', 'integer', 'min:0', 'max:65535'], $rules['sort_order']);
        $this->assertSame(
            ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:min_width=200,min_height=200'],
            $rules['preview_image'],
        );
        $this->assertSame(['nullable', 'string', 'max:65535'], $rules['ai_course_prompt']);
    }

    public function test_messages_are_human_readable(): void
    {
        $messages = (new AdminCreateCourseRequest)->messages();

        $this->assertSame('Название курса обязательно', $messages['title.required']);
        $this->assertSame('Описание курса не может превышать 65535 символов', $messages['description.max']);
        $this->assertSame('Недопустимый статус курса', $messages['status.Illuminate\Validation\Rules\Enum']);
        $this->assertSame(
            'Превью курса должно быть изображением формата jpg, jpeg, png или webp',
            $messages['preview_image.mimes'],
        );
        $this->assertSame('Превью курса должно быть не меньше 200×200 пикселей', $messages['preview_image.dimensions']);
        $this->assertSame('Порядок курса должен быть целым числом', $messages['sort_order.integer']);
        $this->assertSame('Порядок курса не может быть отрицательным', $messages['sort_order.min']);
        $this->assertSame('Порядок курса не может превышать 65535', $messages['sort_order.max']);
    }

    public function test_valid_payload_passes(): void
    {
        $validator = Validator::make([
            'title' => 'Основы SQL',
            'description' => 'Базовый курс по базам данных',
            'status' => CourseStatus::Draft->value,
            'preview_image' => UploadedFile::fake()->image('preview.jpg', 300, 300),
            'ai_course_prompt' => 'Помогай обучающемуся по теме курса.',
        ], (new AdminCreateCourseRequest)->rules());

        $this->assertTrue($validator->passes());
    }

    public function test_payload_without_optional_fields_passes(): void
    {
        $validator = Validator::make([
            'title' => 'Основы SQL',
            'description' => 'Базовый курс по базам данных',
            'status' => CourseStatus::Published->value,
        ], (new AdminCreateCourseRequest)->rules());

        $this->assertTrue($validator->passes());
    }

    public function test_valid_sort_order_passes(): void
    {
        $validator = Validator::make([
            'title' => 'Основы SQL',
            'description' => 'Базовый курс по базам данных',
            'status' => CourseStatus::Draft->value,
            'sort_order' => 42,
        ], (new AdminCreateCourseRequest)->rules());

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
        $request = new AdminCreateCourseRequest;
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
            'title missing' => [[
                'description' => 'Описание',
                'status' => CourseStatus::Draft->value,
            ], 'title'],
            'unknown status value' => [[
                'title' => 'Курс',
                'description' => 'Описание',
                'status' => 'Frozen',
            ], 'status'],
            'non-image preview' => [[
                'title' => 'Курс',
                'description' => 'Описание',
                'status' => CourseStatus::Draft->value,
                'preview_image' => UploadedFile::fake()->create('document.pdf', 100),
            ], 'preview_image'],
            'preview smaller than 200x200' => [[
                'title' => 'Курс',
                'description' => 'Описание',
                'status' => CourseStatus::Draft->value,
                'preview_image' => UploadedFile::fake()->image('small.jpg', 100, 100),
            ], 'preview_image'],
            'preview larger than 2048 kilobytes' => [[
                'title' => 'Курс',
                'description' => 'Описание',
                'status' => CourseStatus::Draft->value,
                'preview_image' => UploadedFile::fake()->create('big.jpg', 3000),
            ], 'preview_image'],
            'description longer than 65535 chars' => [[
                'title' => 'Курс',
                'description' => str_repeat('а', 65536),
                'status' => CourseStatus::Draft->value,
            ], 'description'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[DataProvider('invalidPayloadProvider')]
    public function test_invalid_payload_fails(array $data, string $expectedErrorField): void
    {
        $validator = Validator::make($data, (new AdminCreateCourseRequest)->rules());

        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has($expectedErrorField));
    }
}
