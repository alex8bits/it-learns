<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminPromptHistoryIndexRequest;
use App\Models\AiPromptVersion;
use App\Services\Ai\PromptVersionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PromptHistoryController extends Controller
{
    /**
     * Version history for a prompt key, newest first. Authors are
     * eager-loaded inside PromptVersionService::getHistory (rule #9:
     * no N+1 for collections handed to a view).
     */
    public function index(AdminPromptHistoryIndexRequest $request): Response
    {
        $this->authorize('viewAny', AiPromptVersion::class);

        $key = (string) $request->validated('key');

        return Inertia::render('Admin/Prompts/History', [
            'versions' => app(PromptVersionService::class)->getHistory($key),
            'promptKey' => $key,
        ]);
    }

    /**
     * Roll back by re-issuing an older body as a brand-new version.
     * The service stamps the canonical `Rollback to v{N}` comment;
     * existing rows are never mutated (linear history).
     *
     * No FormRequest: the endpoint takes no input — the version comes from
     * route-model binding and the author from the session.
     */
    public function rollback(Request $request, AiPromptVersion $version): RedirectResponse
    {
        $this->authorize('update', $version);

        app(PromptVersionService::class)->rollbackTo($version, $request->user());

        return redirect()
            ->route('admin.prompts.history.index', ['key' => $version->prompt_key])
            ->with('status', "Откат к версии v{$version->version_number} выполнен");
    }
}
