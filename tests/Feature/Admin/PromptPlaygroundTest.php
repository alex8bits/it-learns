<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\AdminAuditAction;
use App\Enums\AiTokenUsageAction;
use App\Enums\UserRole;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PromptPlaygroundTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate(UserRole::User->value, 'web');
        Role::findOrCreate(UserRole::Admin->value, 'web');
    }

    public function test_admin_opens_the_playground_page(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get(route('admin.prompts.playground'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Prompts/Playground')
            ->has('courses', 0)
            ->where('result', null));
    }

    public function test_admin_runs_the_playground(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->post(route('admin.prompts.playground.run'), [
            'user_message' => 'Объясни JOIN в SQL',
        ]);

        $response->assertRedirect(route('admin.prompts.playground'));

        // Flash carries the full result payload for the next render.
        $result = session('playground_result');
        $this->assertNotNull($result);
        $this->assertSame(
            ['content', 'tokens_used', 'model', 'system_prompt'],
            array_keys($result),
        );
        $this->assertSame('dummy', $result['model']);

        // The spend and the audit row landed (dummy provider, no network).
        $this->assertDatabaseHas('ai_token_usages', [
            'user_id' => $admin->id,
            'action' => AiTokenUsageAction::Playground->value,
            'model' => 'dummy',
        ]);
        $this->assertDatabaseHas('admin_audit_logs', [
            'admin_id' => $admin->id,
            'action' => AdminAuditAction::PromptPlaygroundRun->value,
        ]);
    }

    public function test_non_admin_is_forbidden(): void
    {
        $user = User::factory()->create();
        $user->assignRole(UserRole::User->value);

        $this->actingAs($user)->get(route('admin.prompts.playground'))->assertForbidden();
        $this->actingAs($user)->post(route('admin.prompts.playground.run'), [
            'user_message' => 'запрос',
        ])->assertForbidden();

        $this->assertDatabaseCount('ai_token_usages', 0);
    }
}
