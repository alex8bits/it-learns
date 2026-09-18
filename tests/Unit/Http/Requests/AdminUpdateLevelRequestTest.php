<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Requests;

use App\Http\Requests\Admin\AdminUpdateLevelRequest;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminUpdateLevelRequestTest extends TestCase
{
    public function test_authorize_returns_true(): void
    {
        $this->assertTrue((new AdminUpdateLevelRequest)->authorize());
    }

    public function test_rules_cover_only_mutable_fields(): void
    {
        $rules = (new AdminUpdateLevelRequest)->rules();

        $this->assertSame(['required', 'string', 'max:255'], $rules['title']);
        $this->assertSame(['required', 'integer', 'min:0'], $rules['order']);
        $this->assertArrayNotHasKey('level', $rules);
    }

    public function test_messages_are_human_readable(): void
    {
        $messages = (new AdminUpdateLevelRequest)->messages();

        $this->assertSame('Название уровня обязательно', $messages['title.required']);
        $this->assertSame('Название уровня должно быть строкой', $messages['title.string']);
        $this->assertSame('Порядковый номер уровня обязателен', $messages['order.required']);
        $this->assertSame('Порядковый номер уровня должен быть целым числом', $messages['order.integer']);
        $this->assertSame('Порядковый номер уровня не может быть отрицательным', $messages['order.min']);
    }

    public function test_valid_payload_passes(): void
    {
        $validator = Validator::make([
            'title' => 'Практика для продолжающих',
            'order' => 2,
        ], (new AdminUpdateLevelRequest)->rules());

        $this->assertTrue($validator->passes());
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidPayloadProvider(): array
    {
        return [
            'title missing' => [['order' => 1], 'title'],
            'title is null' => [['title' => null, 'order' => 1], 'title'],
            'title is an empty string' => [['title' => '', 'order' => 1], 'title'],
            'title is not a string' => [['title' => ['Азы'], 'order' => 1], 'title'],
            'order missing' => [['title' => 'Азы'], 'order'],
            'order is negative' => [['title' => 'Азы', 'order' => -5], 'order'],
            'order is a fractional number' => [['title' => 'Азы', 'order' => 1.5], 'order'],
            'order is not an integer' => [['title' => 'Азы', 'order' => 'second'], 'order'],
            'title longer than 255 chars' => [['title' => str_repeat('а', 256), 'order' => 1], 'title'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[DataProvider('invalidPayloadProvider')]
    public function test_invalid_payload_fails(array $data, string $expectedErrorField): void
    {
        $validator = Validator::make($data, (new AdminUpdateLevelRequest)->rules());

        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has($expectedErrorField));
    }
}
