<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\VerifyPracticeTask;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminPracticeTaskCheckRequest;
use App\Http\Resources\PracticeCheckResource;

/**
 * Single-action controller: POST /admin/practice-tasks/check. Consumed
 * by a plain fetch() call from the Create/Edit practice-task forms (no
 * Inertia navigation — the form state survives the check). The JSON
 * response is assembled by PracticeCheckResource.
 */
class CheckPracticeTaskController extends Controller
{
    public function __invoke(AdminPracticeTaskCheckRequest $request): PracticeCheckResource
    {
        $attributes = $request->validated();

        $outcome = app(VerifyPracticeTask::class)->execute(
            $request->user(),
            $attributes['code'],
            $attributes['seed_sql'] ?? null,
            $request->decodedRows(),
        );

        return new PracticeCheckResource($outcome);
    }
}
