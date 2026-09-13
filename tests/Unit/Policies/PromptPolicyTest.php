<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Enums\UserRole;
use App\Models\User;
use App\Policies\PromptPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PromptPolicyTest extends TestCase
{
    private PromptPolicy $policy;

    private User $admin;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate(UserRole::User->value, 'web');
        Role::findOrCreate(UserRole::Admin->value, 'web');

        $this->policy = new PromptPolicy;
        $this->admin = User::factory()->admin()->create();
        $this->user = User::factory()->create();
    }

    #[DataProvider('abilityProvider')]
    public function test_all_abilities_deny_admin(string $ability): void
    {
        $this->assertFalse($this->callAbility($ability, $this->admin));
    }

    #[DataProvider('abilityProvider')]
    public function test_all_abilities_deny_user(string $ability): void
    {
        $this->assertFalse($this->callAbility($ability, $this->user));
    }

    #[DataProvider('abilityProvider')]
    public function test_all_abilities_deny_guest(string $ability): void
    {
        $this->assertFalse($this->callAbility($ability, null));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function abilityProvider(): array
    {
        return [
            'viewAny' => ['viewAny'],
            'view' => ['view'],
            'create' => ['create'],
            'update' => ['update'],
            'delete' => ['delete'],
        ];
    }

    /**
     * Stub policies take `mixed $model` for not-yet-existing models, so the
     * model argument is always null regardless of the ability.
     */
    private function callAbility(string $ability, ?User $actor): bool
    {
        return match ($ability) {
            'viewAny' => $this->policy->viewAny($actor),
            'view' => $this->policy->view($actor, null),
            'create' => $this->policy->create($actor),
            'update' => $this->policy->update($actor, null),
            'delete' => $this->policy->delete($actor, null),
            default => throw new InvalidArgumentException("Unknown ability [{$ability}]"),
        };
    }
}
