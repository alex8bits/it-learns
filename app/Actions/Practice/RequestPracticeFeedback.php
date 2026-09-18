<?php

declare(strict_types=1);

namespace App\Actions\Practice;

use App\Enums\PracticeAttemptStatus;
use App\Models\PracticeTask;
use App\Models\PracticeTaskFeedback;
use App\Models\PracticeTaskSubmission;
use App\Models\User;
use App\Services\Ai\AiFeedbackService;
use App\Services\Ai\AiLimitExceededException;
use App\Services\Ai\Dto\FeedbackInput;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Premium AI feedback on an unsuccessful practice attempt (Stage 8, the
 * FeedbackInput PHPDoc contract: Stage 8 constructs it from a
 * PracticeTaskSubmission). The latest attempt of the user is the one
 * being explained; premium itself is not re-checked here — the
 * EnsurePremium middleware on the HTTP route owns it.
 *
 * AiLimitExceededException is deliberately NOT caught (rule #16: fail
 * loud, no retries): it bubbles to the global renderable in
 * bootstrap/app.php which maps it to HTTP 429 before any token is
 * spent — the guard fires before the provider call.
 */
final class RequestPracticeFeedback
{
    public function __construct(private AiFeedbackService $feedbackService) {}

    /**
     * Explain the user's latest failed/error attempt and persist the
     * feedback row bound to that attempt.
     *
     * @throws NotFoundHttpException when the user has made no attempt at the task
     * @throws ValidationException when the latest attempt is Passed (nothing to explain)
     * @throws AiLimitExceededException when a daily token budget is exhausted (bubbles up, mapped to 429)
     */
    public function execute(User $user, PracticeTask $task): PracticeTaskFeedback
    {
        $task->loadMissing('lesson.level.course');

        $submission = PracticeTaskSubmission::query()
            ->where('user_id', $user->id)
            ->where('practice_task_id', $task->id)
            ->latest('created_at')
            ->first();

        if ($submission === null) {
            throw new NotFoundHttpException('No practice attempt to explain.');
        }

        if ($submission->status === PracticeAttemptStatus::Passed) {
            throw ValidationException::withMessages([
                'submission' => 'Фидбэк доступен только после неудачной попытки.',
            ]);
        }

        $input = new FeedbackInput(
            taskText: $task->statement,
            expectedResult: $task->expected_result_text,
            submittedSolution: $submission->code,
            errorMessage: $submission->error_text ?? 'Результат не совпал с эталоном',
            coursePrompt: $task->lesson->level->course->ai_course_prompt,
        );

        // The LLM call runs before the write: a refused budget (or a
        // provider failure) must not leave a feedback row behind.
        $body = $this->feedbackService->generateFeedback($input, $user);

        return DB::transaction(function () use ($submission, $user, $body): PracticeTaskFeedback {
            return PracticeTaskFeedback::query()->create([
                'practice_task_submission_id' => $submission->id,
                'user_id' => $user->id,
                'body' => $body,
                'created_at' => now(),
            ]);
        });
    }
}
