<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Tests\TestCase;

class LoginTest extends TestCase
{
    public function test_user_can_login_with_valid_credentials(): void
    {
        $user = User::factory()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
    }

    public function test_invalid_credentials_redirect_back_with_shared_errors(): void
    {
        $response = $this->post('/login', [
            'email' => 'nonexistent@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertRedirect();
        $this->assertGuest();

        // The redirect target must re-render with the flashed errors in the
        // Inertia shared `errors` prop — that is what the login page's
        // `form.errors.email` display consumes. Guards against the middleware
        // resolving `errors` before StartSession (empty on every page).
        $this->get('/login')->assertOk()->assertInertia(
            fn ($page) => $page->where('errors.email', __('auth.failed')),
        );
    }
}
