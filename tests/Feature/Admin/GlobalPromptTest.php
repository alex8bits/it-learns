<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\AdminAuditAction;
use App\Enums\UserRole;
use App\Models\Setting;
use App\Models\User;
use App\Services\Ai\PromptKeys;
use App\Services\Ai\PromptVersionService;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class GlobalPromptTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate(UserRole::User->value, 'web');
        Role::findOrCreate(UserRole::Admin->value, 'web');
    }

    public function test_admin_updates_global_prompt(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->patch(route('admin.prompts.global.update'), [
            'body' => 'Новый системный промпт',
            'comment' => 'Первая правка',
        ]);

        $response->assertRedirect(route('admin.prompts.index'));
        $this->assertDatabaseHas('ai_prompt_versions', [
            'prompt_key' => PromptKeys::GLOBAL_SYSTEM_PROMPT,
            'body' => 'Новый системный промпт',
            'comment' => 'Первая правка',
            'version_number' => 1,
            'created_by' => $admin->id,
        ]);
        $this->assertSame(
            'Новый системный промпт',
            Setting::findByKey(PromptKeys::GLOBAL_SYSTEM_PROMPT)?->value,
        );
        $this->assertDatabaseHas('admin_audit_logs', [
            'action' => AdminAuditAction::PromptVersionCreated->value,
            'admin_id' => $admin->id,
        ]);
    }

    public function test_admin_sees_history_and_rolls_back(): void
    {
        $admin = User::factory()->admin()->create();
        $service = app(PromptVersionService::class);
        $v1 = $service->createNewVersion(PromptKeys::GLOBAL_SYSTEM_PROMPT, 'Тело v1', null, $admin);
        $v2 = $service->createNewVersion(PromptKeys::GLOBAL_SYSTEM_PROMPT, 'Тело v2', null, $admin);

        $response = $this->actingAs($admin)->get(route('admin.prompts.history.index', [
            'key' => PromptKeys::GLOBAL_SYSTEM_PROMPT,
        ]));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Prompts/History')
            ->where('promptKey', PromptKeys::GLOBAL_SYSTEM_PROMPT)
            ->has('versions', 2));

        $rollback = $this->actingAs($admin)->post(route('admin.prompts.history.rollback', $v1));

        $rollback->assertRedirect(route('admin.prompts.history.index', [
            'key' => PromptKeys::GLOBAL_SYSTEM_PROMPT,
        ]));
        $this->assertDatabaseHas('ai_prompt_versions', [
            'version_number' => 3,
            'comment' => 'Rollback to v1',
            'body' => 'Тело v1',
        ]);
        // Existing rows stay untouched (append-only history).
        $this->assertDatabaseHas('ai_prompt_versions', ['id' => $v1->id, 'body' => 'Тело v1']);
        $this->assertDatabaseHas('ai_prompt_versions', ['id' => $v2->id, 'body' => 'Тело v2']);
    }

    public function test_non_admin_gets_403_on_prompts(): void
    {
        $user = User::factory()->create();
        $user->assignRole(UserRole::User->value);

        $this->actingAs($user)->get(route('admin.prompts.index'))->assertForbidden();
    }
}
