<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Requests;

use App\Http\Requests\Admin\AdminUpdateUserLlmLimitRequest;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminUpdateUserLlmLimitRequestTest extends TestCase
{
    public function test_authorize_returns_true(): void
    {
        $this->assertTrue((new AdminUpdateUserLlmLimitRequest)->authorize());
    }

    public function test_rules_require_bounded_integer(): void
    {
        $rules = (new AdminUpdateUserLlmLimitRequest)->rules();

        $this->assertSame(['required', 'integer', 'min:0', 'max:100000000'], $rules['extra_tokens']);
    }

    public function test_messages_are_human_readable(): void
    {
        $messages = (new AdminUpdateUserLlmLimitRequest)->messages();

        $this->assertSame('Необходимо указать количество дополнительных токенов', $messages['extra_tokens.required']);
        $this->assertSame('Количество дополнительных токенов должно быть целым числом', $messages['extra_tokens.integer']);
        $this->assertSame('Количество дополнительных токенов не может быть отрицательным', $messages['extra_tokens.min']);
    }

    /**
     * @return array<string, array{0: int|string}>
     */
    public static function validTokensProvider(): array
    {
        return [
            'zero' => [0],
            'small amount' => [500],
            'upper bound' => [100000000],
        ];
    }

    #[DataProvider('validTokensProvider')]
    public function test_valid_values_pass(int|string $extraTokens): void
    {
        $validator = Validator::make(['extra_tokens' => $extraTokens], (new AdminUpdateUserLlmLimitRequest)->rules());

        $this->assertTrue($validator->passes());
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidTokensProvider(): array
    {
        return [
            'missing value' => [[], 'extra_tokens'],
            'negative value' => [['extra_tokens' => -1], 'extra_tokens'],
            'over the upper bound' => [['extra_tokens' => 100000001], 'extra_tokens'],
            'non numeric string' => [['extra_tokens' => 'many'], 'extra_tokens'],
            'float value' => [['extra_tokens' => 1.5], 'extra_tokens'],
            'value is an array' => [['extra_tokens' => [100]], 'extra_tokens'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[DataProvider('invalidTokensProvider')]
    public function test_invalid_values_fail(array $data, string $expectedErrorField): void
    {
        $validator = Validator::make($data, (new AdminUpdateUserLlmLimitRequest)->rules());

        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has($expectedErrorField));
    }
}
