<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\User;
use App\Models\UserLlmLimit;
use Tests\TestCase;

class UserLlmLimitRelationTest extends TestCase
{
    public function test_llm_limit_relation_returns_created_row(): void
    {
        // The relation name is passed explicitly: `has()` would otherwise
        // guess `userLlmLimit()` from the model basename.
        $user = User::factory()
            ->has(UserLlmLimit::factory()->withExtra(1000), 'llmLimit')
            ->create();

        $limit = $user->llmLimit;

        $this->assertInstanceOf(UserLlmLimit::class, $limit);
        $this->assertSame($user->id, $limit->user_id);
        $this->assertSame(1000, $limit->extra_tokens);
    }

    public function test_llm_limit_relation_is_null_without_row(): void
    {
        $user = User::factory()->create();

        $this->assertNull($user->llmLimit);
    }
}
