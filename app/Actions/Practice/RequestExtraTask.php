<?php

declare(strict_types=1);

namespace App\Actions\Practice;

use App\Models\PracticeTask;
use App\Models\User;
use App\Services\Ai\AiLimitExceededException;
use App\Services\Ai\AiTaskGeneratorService;
use App\Services\Ai\Dto\ExtraTaskInput;
use App\Services\Ai\Dto\GeneratedExtraTask;

/**
 * Premium AI generation of an extra practice task in the same style and
 * topic as the reference one (Stage 8). The generated task is shown to
 * the student only — persisting it is an explicit design non-goal, so
 * this action performs no writes at all.
 *
 * AiLimitExceededException is deliberately NOT caught (rule #16: fail
 * loud, no retries): it bubbles to the global renderable in
 * bootstrap/app.php which maps it to HTTP 429 before any token is
 * spent — the guard fires before the provider call.
 */
final class RequestExtraTask
{
    public function __construct(private AiTaskGeneratorService $generatorService) {}

    /**
     * Generate an extra task for the reference one; premium itself is
     * not re-checked here — the EnsurePremium middleware on the HTTP
     * route owns it.
     *
     * @throws AiLimitExceededException when a daily token budget is exhausted (bubbles up, mapped to 429)
     */
    public function execute(User $user, PracticeTask $task): GeneratedExtraTask
    {
        $task->loadMissing('lesson.level.course');

        return $this->generatorService->generateExtraTask(
            new ExtraTaskInput(
                taskText: $task->statement,
                expectedResult: $task->expected_result_text,
                coursePrompt: $task->lesson->level->course->ai_course_prompt,
            ),
            $user,
        );
    }
}
