<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\AdjustUserLlmLimit;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminUpdateUserLlmLimitRequest;
use App\Models\User;
use App\Models\UserLlmLimit;
use App\Services\Ai\AiTokenUsageService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class UserLlmLimitController extends Controller
{
    /**
     * LLM limit adjustment form. A missing `user_llm_limits` row
     * (users registered before Stage 4) renders as `extra_tokens = 0`;
     * `remainingToday` shows the live daily budget after the current
     * spend.
     */
    public function edit(User $user): Response
    {
        $this->authorize('updateLlmLimit', $user);

        return Inertia::render('Admin/Users/LlmLimit', [
            'user' => $user,
            'extraTokens' => UserLlmLimit::find($user->id)->extra_tokens ?? 0,
            'remainingToday' => app(AiTokenUsageService::class)->remainingForUserToday($user),
        ]);
    }

    /**
     * Apply the admin-granted extra token budget. `AdjustUserLlmLimit`
     * wraps the upsert and the audit entry in a single transaction
     * (rule #17).
     */
    public function update(AdminUpdateUserLlmLimitRequest $request, User $user): RedirectResponse
    {
        $this->authorize('updateLlmLimit', $user);

        app(AdjustUserLlmLimit::class)->execute(
            $user,
            (int) $request->validated('extra_tokens'),
            $request->user(),
        );

        return redirect()
            ->route('admin.users.show', $user)
            ->with('status', 'LLM-лимит пользователя обновлён');
    }
}
