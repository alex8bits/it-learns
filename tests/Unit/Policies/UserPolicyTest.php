<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Enums\UserRole;
use App\Models\User;
use App\Policies\UserPolicy;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserPolicyTest extends TestCase
{
    private UserPolicy $policy;

    private User $admin;

    private User $otherAdmin;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate(UserRole::User->value, 'web');
        Role::findOrCreate(UserRole::Admin->value, 'web');

        $this->policy = new UserPolicy;
        $this->admin = User::factory()->admin()->create();
        $this->otherAdmin = User::factory()->admin()->create();
        $this->user = User::factory()->create();
    }

    public function test_view_any_allows_admin(): void
    {
        $this->assertTrue($this->policy->viewAny($this->admin));
    }

    public function test_view_any_denies_user(): void
    {
        $this->assertFalse($this->policy->viewAny($this->user));
    }

    public function test_view_allows_admin_for_any_user(): void
    {
        $this->assertTrue($this->policy->view($this->admin, $this->user));
        $this->assertTrue($this->policy->view($this->admin, $this->otherAdmin));
    }

    public function test_view_denies_user(): void
    {
        $this->assertFalse($this->policy->view($this->user, $this->admin));
    }

    #[DataProvider('selfExclusionProvider')]
    public function test_self_exclusion_matrix(string $method, string $actorKind, string $targetKind, bool $expected): void
    {
        $actor = $actorKind === 'admin' ? $this->otherAdmin : $this->user;

        $target = match ($targetKind) {
            'self' => $actor,
            'other' => $actorKind === 'admin' ? $this->user : $this->admin,
            default => throw new InvalidArgumentException("Unknown target kind [{$targetKind}]"),
        };

        $this->assertSame($expected, $this->callSelfExclusionMethod($method, $actor, $target));
    }

    public function test_gate_resolves_user_policy_by_convention(): void
    {
        $this->assertInstanceOf(UserPolicy::class, Gate::getPolicyFor(User::class));
    }

    /**
     * Every "admin except self" ability shares the same authorization matrix:
     * admin + other user is allowed; admin targeting themselves and any
     * non-admin actor are denied.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: bool}>
     */
    public static function selfExclusionProvider(): array
    {
        return [
            'update allows admin for other user' => ['update', 'admin', 'other', true],
            'update denies admin for self' => ['update', 'admin', 'self', false],
            'update denies regular user' => ['update', 'user', 'other', false],
            'delete allows admin for other user' => ['delete', 'admin', 'other', true],
            'delete denies admin for self' => ['delete', 'admin', 'self', false],
            'delete denies regular user' => ['delete', 'user', 'other', false],
            'changeRole allows admin for other user' => ['changeRole', 'admin', 'other', true],
            'changeRole denies admin for self' => ['changeRole', 'admin', 'self', false],
            'changeRole denies regular user' => ['changeRole', 'user', 'other', false],
            'block allows admin for other user' => ['block', 'admin', 'other', true],
            'block denies admin for self' => ['block', 'admin', 'self', false],
            'block denies regular user' => ['block', 'user', 'other', false],
            'unblock allows admin for other user' => ['unblock', 'admin', 'other', true],
            'unblock denies admin for self' => ['unblock', 'admin', 'self', false],
            'unblock denies regular user' => ['unblock', 'user', 'other', false],
        ];
    }

    private function callSelfExclusionMethod(string $method, User $actor, User $target): bool
    {
        return match ($method) {
            'update' => $this->policy->update($actor, $target),
            'delete' => $this->policy->delete($actor, $target),
            'changeRole' => $this->policy->changeRole($actor, $target),
            'block' => $this->policy->block($actor, $target),
            'unblock' => $this->policy->unblock($actor, $target),
            default => throw new InvalidArgumentException("Unknown method [{$method}]"),
        };
    }
}
