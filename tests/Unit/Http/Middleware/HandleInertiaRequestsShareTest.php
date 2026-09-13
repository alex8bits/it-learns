<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Middleware;

use App\Enums\UserRole;
use App\Http\Middleware\HandleInertiaRequests;
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

    public function test_csrf_is_shared_as_string(): void
    {
        $props = $this->sharedProps();

        $this->assertIsString($props['csrf']);
        $this->assertNotSame('', $props['csrf']);
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
            ['id', 'name', 'email', 'roles', 'is_blocked'],
            array_keys($sharedUser),
        );
        $this->assertSame($user->id, $sharedUser['id']);
        $this->assertSame($user->name, $sharedUser['name']);
        $this->assertSame($user->email, $sharedUser['email']);
        $this->assertSame([], $sharedUser['roles']);
        $this->assertFalse($sharedUser['is_blocked']);
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
     * Session store backing the app (the same singleton backs csrf_token()).
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

    private function sharedUser(): mixed
    {
        $auth = $this->sharedProps()['auth'];
        assert(is_array($auth));

        return $auth['user'];
    }
}
