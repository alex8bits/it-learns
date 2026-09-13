<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Requests;

use App\Enums\UserRole;
use App\Http\Requests\Admin\AdminUpdateUserRequest;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Enum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminUpdateUserRequestTest extends TestCase
{
    public function test_authorize_returns_true(): void
    {
        $this->assertTrue((new AdminUpdateUserRequest)->authorize());
    }

    public function test_rules_require_string_role_enum(): void
    {
        $rules = (new AdminUpdateUserRequest)->rules();

        $this->assertCount(3, $rules['role']);
        $this->assertSame('required', $rules['role'][0]);
        $this->assertSame('string', $rules['role'][1]);
        $this->assertInstanceOf(Enum::class, $rules['role'][2]);
    }

    public function test_messages_are_human_readable(): void
    {
        $messages = (new AdminUpdateUserRequest)->messages();

        $this->assertSame('Необходимо выбрать роль', $messages['role.required']);
        $this->assertSame('Недопустимое значение роли', $messages['role.Illuminate\Validation\Rules\Enum']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function validRoleProvider(): array
    {
        $cases = [];
        foreach (UserRole::cases() as $role) {
            $cases[$role->name] = [$role->value];
        }

        return $cases;
    }

    #[DataProvider('validRoleProvider')]
    public function test_each_enum_role_value_passes(string $role): void
    {
        $validator = Validator::make(['role' => $role], (new AdminUpdateUserRequest)->rules());

        $this->assertTrue($validator->passes());
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidRoleProvider(): array
    {
        return [
            'unknown role value' => [['role' => 'superadmin'], 'role'],
            'missing role' => [[], 'role'],
            'empty role' => [['role' => ''], 'role'],
            'role is an array' => [['role' => [UserRole::User->value]], 'role'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[DataProvider('invalidRoleProvider')]
    public function test_invalid_role_fails(array $data, string $expectedErrorField): void
    {
        $validator = Validator::make($data, (new AdminUpdateUserRequest)->rules());

        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has($expectedErrorField));
    }
}
