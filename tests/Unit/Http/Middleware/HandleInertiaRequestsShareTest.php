<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Middleware;

use App\Enums\UserRole;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Subscriptions\SubscriptionService;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\Store;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class HandleInertiaRequestsShareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate(UserRole::User->value, 'web');
        Role::findOrCreate(UserRole::Admin->value, 'web');
    }

    public function test_auth_prop_is_shared_lazily_as_callable(): void
    {
        $this->assertIsCallable($this->sharedProps()['auth']);
    }

    public function test_auth_user_is_null_for_guest(): void
    {
        $this->assertNull($this->sharedUser());
    }

    public function test_auth_prop_for_guest_never_touches_subscription_service(): void
    {
        $this->mock(SubscriptionService::class)->shouldNotReceive('isActive');

        $this->assertSame(['user' => null], $this->authProp());
    }

    public function test_auth_prop_reflects_user_authenticated_after_share_runs(): void
    {
        // Production order: route-level `auth` middleware runs after the
        // group middleware, so share() executes before the user resolver is
        // attached — the user only becomes visible when the lazy prop is
        // resolved at render time, never when share() itself executes.
        $auth = $this->sharedProps()['auth'];
        assert(is_callable($auth));

        $user = User::factory()->create();
        $this->actingAs($user);

        $sharedUser = $this->userFromAuthProp($auth);
        assert(is_array($sharedUser));

        $this->assertSame($user->id, $sharedUser['id']);
        $this->assertSame($user->name, $sharedUser['name']);
    }

    public function test_auth_user_exposes_expected_shape_for_authenticated_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $sharedUser = $this->sharedUser();
        assert(is_array($sharedUser));

        $this->assertEqualsCanonicalizing(
            ['id', 'name', 'email', 'roles', 'is_blocked', 'is_premium'],
            array_keys($sharedUser),
        );
        $this->assertSame($user->id, $sharedUser['id']);
        $this->assertSame($user->name, $sharedUser['name']);
        $this->assertSame($user->email, $sharedUser['email']);
        $this->assertSame([], $sharedUser['roles']);
        $this->assertFalse($sharedUser['is_blocked']);
        $this->assertFalse($sharedUser['is_premium']);
    }

    public function test_auth_user_roles_and_blocked_flag_reflect_model_state(): void
    {
        $user = User::factory()->admin()->blocked()->create();

        $this->actingAs($user);

        $sharedUser = $this->sharedUser();
        assert(is_array($sharedUser));

        $this->assertSame([UserRole::Admin->value], $sharedUser['roles']);
        $this->assertTrue($sharedUser['is_blocked']);
    }

    public function test_is_premium_is_true_for_user_with_in_force_subscription(): void
    {
        $user = User::factory()->create();
        Subscription::factory()->active()->create(['user_id' => $user]);

        $this->actingAs($user);

        $sharedUser = $this->sharedUser();
        assert(is_array($sharedUser));

        $this->assertTrue($sharedUser['is_premium']);
    }

    public function test_is_premium_is_false_for_user_with_expired_subscription(): void
    {
        $user = User::factory()->create();
        Subscription::factory()->expired()->create(['user_id' => $user]);

        $this->actingAs($user);

        $sharedUser = $this->sharedUser();
        assert(is_array($sharedUser));

        $this->assertFalse($sharedUser['is_premium']);
    }

    public function test_status_is_null_without_flash(): void
    {
        $status = $this->sharedProps()['status'];
        assert($status instanceof Closure);

        $this->assertNull($status());
    }

    public function test_status_is_shared_from_session_flash(): void
    {
        $this->sessionStore()->flash('status', 'Ссылка для сброса пароля отправлена.');

        $status = $this->sharedProps()['status'];
        assert($status instanceof Closure);

        $this->assertSame('Ссылка для сброса пароля отправлена.', $status());
    }

    /**
     * Session store backing the application.
     */
    private function sessionStore(): Store
    {
        return $this->app->make(Store::class);
    }

    /**
     * Run share() against the framework-bound request with a started session
     * attached, mirroring the StartSession -> HandleInertiaRequests flow of a
     * real Inertia page render.
     *
     * @return array<string, mixed>
     */
    private function sharedProps(): array
    {
        $store = $this->sessionStore();
        if (! $store->isStarted()) {
            $store->start();
        }

        $request = $this->app->make(Request::class);
        $request->setLaravelSession($store);

        // Rebinding attaches the auth user resolver to the request, mirroring
        // the real request lifecycle: actingAs() users become visible to
        // $request->user() inside the middleware.
        $this->app->instance('request', $request);

        return app(HandleInertiaRequests::class)->share($request);
    }

    /**
     * Resolve the lazy `auth` prop the way the Inertia PropsResolver would:
     * the callable is invoked at render time, never inside share() itself.
     *
     * @return array<string, mixed>
     */
    private function authProp(): array
    {
        $auth = $this->sharedProps()['auth'];
        assert(is_callable($auth));

        /** @var array<string, mixed> $resolved */
        $resolved = $auth();
        assert(is_array($resolved));

        return $resolved;
    }

    /**
     * Resolve `auth.user` from an already-shared `auth` callable (used to
     * authenticate a user AFTER share() has run, as in production).
     */
    private function userFromAuthProp(callable $auth): mixed
    {
        /** @var array<string, mixed> $resolved */
        $resolved = $auth();
        assert(is_array($resolved));

        return $resolved['user'];
    }

    private function sharedUser(): mixed
    {
        return $this->authProp()['user'];
    }
}
