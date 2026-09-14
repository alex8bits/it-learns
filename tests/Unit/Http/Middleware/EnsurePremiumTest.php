<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Middleware;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class EnsurePremiumTest extends TestCase
{
    /**
     * Stand-in routes, never attached to production routes: the middleware
     * only needs a route aliased `ensurepremium` and the `pricing` name to
     * exist for `redirect()->route('pricing')`. The real pricing page is
     * added by Task 04, so the stand-in is only registered when missing.
     *
     * The `pricing` name rides in the action array (not a chained ->name()):
     * routes registered here are added to the collection before a chained
     * name is applied, and the route name lookup table is not refreshed
     * again in the test lifetime.
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (! Route::has('pricing')) {
            Route::get('_test/pricing', ['as' => 'pricing', fn () => 'pricing']);
        }

        Route::get('_test/premium', fn () => 'ok')->middleware(['web', 'ensurepremium']);
    }

    public function test_premium_user_passes_through(): void
    {
        $user = User::factory()->create();
        Subscription::factory()->active()->create(['user_id' => $user]);

        $response = $this->actingAs($user)->get('_test/premium');

        $response->assertOk();
        $this->assertSame('ok', $response->getContent());
    }

    public function test_free_user_web_request_is_redirected_to_pricing_with_status_flash(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('_test/premium');

        $response->assertRedirect(route('pricing'));
        $response->assertSessionHas('status', 'Эта возможность доступна только с премиум-подпиской.');
    }

    public function test_guest_web_request_is_redirected_to_pricing(): void
    {
        $response = $this->get('_test/premium');

        $response->assertRedirect(route('pricing'));
        $response->assertSessionHas('status', 'Эта возможность доступна только с премиум-подпиской.');
    }

    public function test_free_user_json_request_gets_403_with_message(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('_test/premium');

        $response->assertForbidden();
        $response->assertJsonPath('message', 'Доступно только с премиум-подпиской.');
    }
}
