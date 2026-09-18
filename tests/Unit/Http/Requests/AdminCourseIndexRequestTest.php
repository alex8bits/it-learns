<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Requests;

use App\Enums\CourseStatus;
use App\Http\Requests\Admin\AdminCourseIndexRequest;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Enum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminCourseIndexRequestTest extends TestCase
{
    public function test_authorize_returns_true(): void
    {
        $this->assertTrue((new AdminCourseIndexRequest)->authorize());
    }

    public function test_rules_cover_status_filter(): void
    {
        $rules = (new AdminCourseIndexRequest)->rules();

        $this->assertCount(2, $rules['status']);
        $this->assertSame('nullable', $rules['status'][0]);
        $this->assertInstanceOf(Enum::class, $rules['status'][1]);
    }

    public function test_messages_are_human_readable(): void
    {
        $messages = (new AdminCourseIndexRequest)->messages();

        $this->assertSame(
            'Недопустимое значение статуса курса',
            $messages['status.Illuminate\Validation\Rules\Enum'],
        );
    }

    public function test_valid_status_filter_passes(): void
    {
        $validator = Validator::make(
            ['status' => CourseStatus::Published->value],
            (new AdminCourseIndexRequest)->rules(),
        );

        $this->assertTrue($validator->passes());
    }

    public function test_each_valid_status_value_passes(): void
    {
        $rules = (new AdminCourseIndexRequest)->rules();

        foreach (CourseStatus::cases() as $status) {
            $validator = Validator::make(['status' => $status->value], $rules);
            $this->assertTrue($validator->passes(), "Status {$status->value} should pass validation");
        }
    }

    public function test_empty_filters_pass(): void
    {
        $validator = Validator::make([], (new AdminCourseIndexRequest)->rules());

        $this->assertTrue($validator->passes());
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidFilterProvider(): array
    {
        return [
            'unknown status value' => [['status' => 'Frozen'], 'status'],
            'status is an array' => [['status' => ['Draft', 'Published']], 'status'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[DataProvider('invalidFilterProvider')]
    public function test_invalid_filters_fail(array $data, string $expectedErrorField): void
    {
        $validator = Validator::make($data, (new AdminCourseIndexRequest)->rules());

        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has($expectedErrorField));
    }
}
