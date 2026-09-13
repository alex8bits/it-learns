<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ForgotPasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_link_is_sent_for_existing_user(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $response = $this->post('/forgot-password', ['email' => $user->email]);

        $response->assertSessionHas('status');
        Notification::assertSentTo($user, ResetPasswordNotification::class);
    }

    public function test_no_reset_link_is_sent_for_nonexistent_user(): void
    {
        Notification::fake();

        $response = $this->from('/forgot-password')->post('/forgot-password', ['email' => 'nonexistent@example.com']);

        // Fortify's FailedPasswordResetLinkRequestResponse (vendor/laravel/fortify/src/Http/Responses/...)
        // returns a back()->withErrors() instead of back()->with('status', ...) for the unknown-user case.
        // That is a user-enumeration leak: the user sees a different error message than when the email
        // exists. Hardening this requires swapping the response contract binding in a Fortify service
        // provider (out of scope for task 07 — "tests only"). For now the smoke test still verifies
        // that no notification was sent, which is the security-critical assertion.
        $response->assertSessionHasErrors('email');
        Notification::assertNothingSent();
    }
}
