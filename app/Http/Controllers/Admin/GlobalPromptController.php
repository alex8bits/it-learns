<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminUpdateGlobalPromptRequest;
use App\Models\AiPromptVersion;
use App\Models\Setting;
use App\Services\Ai\PromptKeys;
use App\Services\Ai\PromptVersionService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class GlobalPromptController extends Controller
{
    /**
     * Global system prompt editor. The textarea is seeded from the
     * cached `settings` row — the fast-read copy of the active
     * version's body maintained by PromptVersionService.
     */
    public function edit(): Response
    {
        $this->authorize('viewAny', AiPromptVersion::class);

        return Inertia::render('Admin/Prompts/Index', [
            'prompt' => Setting::findByKey(PromptKeys::GLOBAL_SYSTEM_PROMPT)->value ?? '',
        ]);
    }

    /**
     * Save the edited prompt as a brand-new version via
     * PromptVersionService (append-only history + `settings` cache
     * refresh + audit log, all in one transaction).
     */
    public function update(AdminUpdateGlobalPromptRequest $request): RedirectResponse
    {
        $this->authorize('update', AiPromptVersion::class);

        $comment = $request->validated('comment');

        app(PromptVersionService::class)->createNewVersion(
            PromptKeys::GLOBAL_SYSTEM_PROMPT,
            (string) $request->validated('body'),
            is_string($comment) ? $comment : null,
            $request->user(),
        );

        return redirect()
            ->route('admin.prompts.index')
            ->with('status', 'Промпт обновлён — создана новая версия');
    }
}
