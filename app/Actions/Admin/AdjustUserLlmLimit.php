<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\AdminAuditAction;
use App\Models\User;
use App\Models\UserLlmLimit;
use App\Services\Admin\AdminAuditLogger;
use Illuminate\Support\Facades\DB;

class AdjustUserLlmLimit
{
    public function __construct(private AdminAuditLogger $audit) {}

    /**
     * Atomically set the user's admin-granted extra token budget and
     * record an audit entry. The previous value is captured *before*
     * the upsert so `meta.old_extra` reflects the pre-change state; a
     * missing `user_llm_limits` row counts as `extra_tokens = 0` (users
     * registered before Stage 4).
     */
    public function execute(User $target, int $extraTokens, User $admin): void
    {
        DB::transaction(function () use ($target, $extraTokens): void {
            $old = (int) (UserLlmLimit::query()
                ->where('user_id', $target->id)
                ->value('extra_tokens') ?? 0);

            UserLlmLimit::query()->updateOrCreate(
                ['user_id' => $target->id],
                ['extra_tokens' => $extraTokens],
            );

            $this->audit->log(
                AdminAuditAction::UserLlmLimitAdjusted,
                $target,
                ['old_extra' => $old, 'new_extra' => $extraTokens],
            );
        });
    }
}
