<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Enums\UserRole;
use App\Models\Course;
use App\Models\User;
use App\Policies\CoursePolicy;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CoursePolicyTest extends TestCase
{
    private CoursePolicy $policy;

    private Course $course;

    private User $admin;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate(UserRole::User->value, 'web');
        Role::findOrCreate(UserRole::Admin->value, 'web');

        $this->policy = new CoursePolicy;
        $this->course = Course::factory()->create();
        $this->admin = User::factory()->admin()->create();
        $this->user = User::factory()->create();
    }

    #[DataProvider('abilityProvider')]
    public function test_admin_is_allowed(string $ability): void
    {
        $this->assertTrue($this->callAbility($ability, $this->admin));
    }

    #[DataProvider('abilityProvider')]
    public function test_regular_user_is_denied(string $ability): void
    {
        $this->assertFalse($this->callAbility($ability, $this->user));
    }

    #[DataProvider('abilityProvider')]
    public function test_guest_is_denied(string $ability): void
    {
        $this->assertFalse($this->callAbilityAsGuest($ability));
    }

    public function test_gate_resolves_policy_by_convention(): void
    {
        $this->assertInstanceOf(CoursePolicy::class, Gate::getPolicyFor(Course::class));
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
            'preview' => ['preview'],
        ];
    }

    private function callAbility(string $ability, User $actor): bool
    {
        return match ($ability) {
            'viewAny' => $this->policy->viewAny($actor),
            'view' => $this->policy->view($actor, $this->course),
            'create' => $this->policy->create($actor),
            'update' => $this->policy->update($actor, $this->course),
            'delete' => $this->policy->delete($actor, $this->course),
            'preview' => $this->policy->preview($actor, $this->course),
            default => throw new InvalidArgumentException("Unknown ability [{$ability}]"),
        };
    }

    /**
     * Policy methods take a non-nullable User, so they are never invoked
     * for guests: the Gate sees a null user, finds a method that does
     * not allow guests, and denies the ability without calling it.
     */
    private function callAbilityAsGuest(string $ability): bool
    {
        $gate = Gate::forUser(null);

        return match ($ability) {
            'viewAny' => $gate->allows('viewAny', Course::class),
            'view' => $gate->allows('view', $this->course),
            'create' => $gate->allows('create', Course::class),
            'update' => $gate->allows('update', $this->course),
            'delete' => $gate->allows('delete', $this->course),
            'preview' => $gate->allows('preview', $this->course),
            default => throw new InvalidArgumentException("Unknown ability [{$ability}]"),
        };
    }
}
