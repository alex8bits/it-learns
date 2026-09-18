<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\User;
use App\Models\UserLlmLimit;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UserLlmLimitTest extends TestCase
{
    public function test_factory_creates_row_with_default_zero_extra_tokens(): void
    {
        $limit = UserLlmLimit::factory()->create();

        $this->assertSame(0, $limit->extra_tokens);
        $this->assertDatabaseHas('user_llm_limits', [
            'user_id' => $limit->user_id,
            'extra_tokens' => 0,
        ]);
    }

    public function test_with_extra_state_sets_extra_tokens(): void
    {
        $limit = UserLlmLimit::factory()->withExtra(2500)->create();

        $this->assertSame(2500, $limit->extra_tokens);
    }

    public function test_user_relation_returns_owner(): void
    {
        $user = User::factory()->create();
        $limit = UserLlmLimit::factory()->create(['user_id' => $user->id]);

        $this->assertTrue($limit->user->is($user));
    }

    public function test_primary_key_is_user_id(): void
    {
        $model = new UserLlmLimit;

        $this->assertSame('user_id', $model->getKeyName());
        $this->assertFalse($model->getIncrementing());
    }

    public function test_table_has_no_surrogate_id_column(): void
    {
        $this->assertFalse(Schema::hasColumn('user_llm_limits', 'id'));
        $this->assertTrue(Schema::hasColumn('user_llm_limits', 'user_id'));
    }

    public function test_second_row_for_same_user_is_rejected(): void
    {
        $user = User::factory()->create();
        UserLlmLimit::factory()->create(['user_id' => $user->id]);

        $this->expectException(QueryException::class);

        UserLlmLimit::factory()->create(['user_id' => $user->id]);
    }
}
