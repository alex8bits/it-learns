<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Requests;

use App\Http\Requests\Admin\AdminLevelRequest;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminLevelRequestTest extends TestCase
{
    public function test_authorize_returns_true(): void
    {
        $this->assertTrue((new AdminLevelRequest)->authorize());
    }

    public function test_rules_cover_title_and_order(): void
    {
        $rules = (new AdminLevelRequest)->rules();

        $this->assertSame(['required', 'string', 'max:255'], $rules['title']);
        $this->assertSame(['nullable', 'integer', 'min:0'], $rules['order']);
    }

    public function test_messages_are_human_readable(): void
    {
        $messages = (new AdminLevelRequest)->messages();

        $this->assertSame('Название уровня обязательно', $messages['title.required']);
        $this->assertSame('Название уровня должно быть строкой', $messages['title.string']);
        $this->assertSame('Название уровня не может превышать 255 символов', $messages['title.max']);
        $this->assertSame('Порядковый номер уровня не может быть отрицательным', $messages['order.min']);
    }

    public function test_valid_payload_passes(): void
    {
        $validator = Validator::make([
            'title' => 'Азы',
            'order' => 1,
        ], (new AdminLevelRequest)->rules());

        $this->assertTrue($validator->passes());
    }

    public function test_payload_without_optional_order_passes(): void
    {
        $validator = Validator::make([
            'title' => 'Азы',
        ], (new AdminLevelRequest)->rules());

        $this->assertTrue($validator->passes());
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidPayloadProvider(): array
    {
        return [
            'title missing' => [['order' => 1], 'title'],
            'title is not a string' => [['title' => ['Азы']], 'title'],
            'title longer than 255 chars' => [
                ['title' => str_repeat('а', 256)],
                'title',
            ],
            'order is negative' => [['title' => 'Азы', 'order' => -1], 'order'],
            'order is not an integer' => [['title' => 'Азы', 'order' => 'first'], 'order'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[DataProvider('invalidPayloadProvider')]
    public function test_invalid_payload_fails(array $data, string $expectedErrorField): void
    {
        $validator = Validator::make($data, (new AdminLevelRequest)->rules());

        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has($expectedErrorField));
    }
}
