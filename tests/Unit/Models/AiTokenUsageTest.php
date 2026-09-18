<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\AiTokenUsageAction;
use App\Models\AiTokenUsage;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AiTokenUsageTest extends TestCase
{
    public function test_factory_creates_row_with_feedback_action(): void
    {
        $usage = AiTokenUsage::factory()->create();

        $this->assertSame(AiTokenUsageAction::Feedback, $usage->action);
        $this->assertDatabaseHas('ai_token_usages', [
            'id' => $usage->id,
            'user_id' => $usage->user_id,
            'model' => 'gpt-test',
            'action' => AiTokenUsageAction::Feedback->value,
        ]);
    }

    public function test_extra_task_state_sets_action(): void
    {
        $usage = AiTokenUsage::factory()->extraTask()->create();

        $this->assertSame(AiTokenUsageAction::ExtraTask, $usage->action);
    }

    public function test_action_attribute_accepts_enum_and_persists_value(): void
    {
        $usage = AiTokenUsage::factory()->create([
            'action' => AiTokenUsageAction::ExtraTask,
            'tokens' => 421,
        ]);

        $this->assertSame(AiTokenUsageAction::ExtraTask, $usage->fresh()->action);
        $this->assertSame(421, $usage->fresh()->tokens);
    }

    public function test_user_relation_returns_spending_user(): void
    {
        $user = User::factory()->create();
        $usage = AiTokenUsage::factory()->create(['user_id' => $user->id]);

        $this->assertTrue($usage->user->is($user));
    }

    public function test_created_at_is_cast_to_datetime(): void
    {
        $usage = AiTokenUsage::factory()->create();

        $this->assertInstanceOf(Carbon::class, $usage->fresh()->created_at);
    }

    public function test_table_is_append_only_without_updated_at(): void
    {
        $this->assertFalse(Schema::hasColumn('ai_token_usages', 'updated_at'));
        $this->assertTrue(Schema::hasColumn('ai_token_usages', 'created_at'));
    }
}
