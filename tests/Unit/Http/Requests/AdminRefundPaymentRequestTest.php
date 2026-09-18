<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Requests;

use App\Http\Requests\Admin\AdminRefundPaymentRequest;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminRefundPaymentRequestTest extends TestCase
{
    public function test_authorize_returns_true(): void
    {
        $this->assertTrue((new AdminRefundPaymentRequest)->authorize());
    }

    public function test_rules_cover_the_reason_field(): void
    {
        $rules = (new AdminRefundPaymentRequest)->rules();

        $this->assertSame(['nullable', 'string', 'max:1000'], $rules['reason']);
    }

    public function test_messages_are_human_readable(): void
    {
        $messages = (new AdminRefundPaymentRequest)->messages();

        $this->assertSame('Причина возврата должна быть строкой', $messages['reason.string']);
        $this->assertSame('Причина возврата не может быть длиннее 1000 символов', $messages['reason.max']);
    }

    public function test_missing_reason_passes(): void
    {
        $validator = Validator::make([], (new AdminRefundPaymentRequest)->rules());

        $this->assertTrue($validator->passes());
    }

    public function test_explicit_null_reason_passes(): void
    {
        $validator = Validator::make(['reason' => null], (new AdminRefundPaymentRequest)->rules());

        $this->assertTrue($validator->passes());
    }

    public function test_string_reason_passes(): void
    {
        $validator = Validator::make(
            ['reason' => 'Двойное списание по ошибке'],
            (new AdminRefundPaymentRequest)->rules(),
        );

        $this->assertTrue($validator->passes());
    }

    public function test_boundary_length_reason_passes(): void
    {
        $validator = Validator::make(
            ['reason' => str_repeat('а', 1000)],
            (new AdminRefundPaymentRequest)->rules(),
        );

        $this->assertTrue($validator->passes());
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidReasonProvider(): array
    {
        return [
            'reason is not a string' => [['reason' => ['not', 'a', 'string']], 'reason'],
            'reason is an integer' => [['reason' => 42], 'reason'],
            'reason is too long' => [['reason' => str_repeat('a', 1001)], 'reason'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[DataProvider('invalidReasonProvider')]
    public function test_invalid_reason_fails(array $data, string $expectedErrorField): void
    {
        $validator = Validator::make($data, (new AdminRefundPaymentRequest)->rules());

        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has($expectedErrorField));
    }
}
