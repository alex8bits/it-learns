<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Middleware;

use App\Enums\UserRole;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Subscription;
use App\Models\User;
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

    public function test_auth_user_is_null_for_guest(): void
    {
        $this->assertNull($this->sharedUser());
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
        $this->assertFalse($this->resolvePremium($sharedUser));
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

        $this->assertTrue($this->resolvePremium($sharedUser));
    }

    public function test_is_premium_is_false_for_user_with_expired_subscription(): void
    {
        $user = User::factory()->create();
        Subscription::factory()->expired()->create(['user_id' => $user]);

        $this->actingAs($user);

        $sharedUser = $this->sharedUser();
        assert(is_array($sharedUser));

        $this->assertFalse($this->resolvePremium($sharedUser));
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
     * `is_premium` is shared lazily (a closure, like `status`), so guest
     * requests and non-Inertia responses never run the subscription query —
     * resolve it the way the Inertia PropsResolver would.
     *
     * @param  array<string, mixed>  $sharedUser
     */
    private function resolvePremium(array $sharedUser): bool
    {
        $premium = $sharedUser['is_premium'];
        assert($premium instanceof Closure);

        return (bool) $premium();
    }

    private function sharedUser(): mixed
    {
        $auth = $this->sharedProps()['auth'];
        assert(is_array($auth));

        return $auth['user'];
    }
}
