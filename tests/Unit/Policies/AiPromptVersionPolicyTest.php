<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Enums\UserRole;
use App\Models\AiPromptVersion;
use App\Models\User;
use App\Policies\AiPromptVersionPolicy;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AiPromptVersionPolicyTest extends TestCase
{
    private AiPromptVersionPolicy $policy;

    private User $admin;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate(UserRole::User->value, 'web');
        Role::findOrCreate(UserRole::Admin->value, 'web');

        $this->policy = new AiPromptVersionPolicy;
        $this->admin = User::factory()->admin()->create();
        $this->user = User::factory()->create();
    }

    #[DataProvider('abilityProvider')]
    public function test_admin_is_allowed(string $ability): void
    {
        $version = AiPromptVersion::factory()->create();

        $this->assertTrue($this->callAbility($ability, $this->admin, $version));
    }

    #[DataProvider('abilityProvider')]
    public function test_regular_user_is_denied(string $ability): void
    {
        $version = AiPromptVersion::factory()->create();

        $this->assertFalse($this->callAbility($ability, $this->user, $version));
    }

    public function test_update_without_model_authorizes_the_class_name_path(): void
    {
        // `authorize('update', AiPromptVersion::class)` invokes the policy
        // without a model instance (Gate strips the class-string argument),
        // so the method must stay callable with the user alone.
        $this->assertTrue($this->policy->update($this->admin));
        $this->assertFalse($this->policy->update($this->user));
    }

    public function test_playground_without_model_authorizes_the_class_name_path(): void
    {
        // `authorize('playground', AiPromptVersion::class)` invokes the
        // policy without a model instance (Gate strips the class-string
        // argument), so the method must stay callable with the user alone.
        $this->assertTrue($this->policy->playground($this->admin));
        $this->assertFalse($this->policy->playground($this->user));
    }

    public function test_gate_resolves_policy_by_convention(): void
    {
        $this->assertInstanceOf(AiPromptVersionPolicy::class, Gate::getPolicyFor(AiPromptVersion::class));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function abilityProvider(): array
    {
        return [
            'viewAny' => ['viewAny'],
            'view' => ['view'],
            'update' => ['update'],
            'playground' => ['playground'],
        ];
    }

    private function callAbility(string $ability, User $actor, AiPromptVersion $version): bool
    {
        return match ($ability) {
            'viewAny' => $this->policy->viewAny($actor),
            'view' => $this->policy->view($actor, $version),
            'update' => $this->policy->update($actor, $version),
            'playground' => $this->policy->playground($actor, $version),
            default => throw new InvalidArgumentException("Unknown ability [{$ability}]"),
        };
    }
}
