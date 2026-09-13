<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Requests;

use App\Enums\UserRole;
use App\Http\Requests\Admin\AdminUserIndexRequest;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Enum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminUserIndexRequestTest extends TestCase
{
    public function test_authorize_returns_true(): void
    {
        $this->assertTrue((new AdminUserIndexRequest)->authorize());
    }

    public function test_rules_cover_email_and_role_filters(): void
    {
        $rules = (new AdminUserIndexRequest)->rules();

        $this->assertSame(['nullable', 'string', 'max:255'], $rules['email']);
        $this->assertCount(2, $rules['role']);
        $this->assertSame('nullable', $rules['role'][0]);
        $this->assertInstanceOf(Enum::class, $rules['role'][1]);
    }

    public function test_messages_are_human_readable(): void
    {
        $messages = (new AdminUserIndexRequest)->messages();

        $this->assertSame('Фильтр по email должен быть строкой', $messages['email.string']);
        $this->assertSame('Фильтр по email не может быть длиннее 255 символов', $messages['email.max']);
        $this->assertSame('Недопустимое значение роли', $messages['role.Illuminate\Validation\Rules\Enum']);
    }

    public function test_valid_filters_pass(): void
    {
        $validator = Validator::make(
            ['email' => 'user@example.com', 'role' => UserRole::Admin->value],
            (new AdminUserIndexRequest)->rules(),
        );

        $this->assertTrue($validator->passes());
    }

    public function test_each_valid_filter_alone_passes(): void
    {
        $rules = (new AdminUserIndexRequest)->rules();

        $emailOnly = Validator::make(['email' => 'example'], $rules);
        $this->assertTrue($emailOnly->passes());

        $roleOnly = Validator::make(['role' => UserRole::User->value], $rules);
        $this->assertTrue($roleOnly->passes());
    }

    public function test_empty_filters_pass(): void
    {
        $validator = Validator::make([], (new AdminUserIndexRequest)->rules());

        $this->assertTrue($validator->passes());
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidFilterProvider(): array
    {
        return [
            'unknown role value' => [['role' => 'superadmin'], 'role'],
            'email longer than 255 chars' => [['email' => str_repeat('a', 256)], 'email'],
            'email is an array' => [['email' => ['a', 'b']], 'email'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[DataProvider('invalidFilterProvider')]
    public function test_invalid_filters_fail(array $data, string $expectedErrorField): void
    {
        $validator = Validator::make($data, (new AdminUserIndexRequest)->rules());

        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has($expectedErrorField));
    }
}
