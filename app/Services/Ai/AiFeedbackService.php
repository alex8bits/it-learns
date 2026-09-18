<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Enums\AiTokenUsageAction;
use App\Models\User;
use App\Services\Ai\Dto\FeedbackInput;

/**
 * Scenario service: explains a failed practice attempt to the student.
 *
 * The pipeline is fixed and shared with AiTaskGeneratorService (the AI
 * seam of Stage 4): quota guard -> prompt resolution -> LlmClient ->
 * usage recording. Premium is not checked here — Stage 8 hangs
 * EnsurePremium on the HTTP routes that call this service.
 */
class AiFeedbackService
{
    /**
     * User-message template. Placeholders `{task}`, `{expected}`,
     * `{submitted}` and `{error}` are filled via str_replace; the
     * surrounding wording is the instruction the model follows.
     */
    private const FEEDBACK_MESSAGE_TEMPLATE = <<<'TXT'
    Объясни студенту, в чём ошибка, кратко и по делу.

    Задание:
    {task}

    Ожидаемый результат:
    {expected}

    Решение студента:
    {submitted}

    Ошибка:
    {error}
    TXT;

    public function __construct(
        private AiLimitGuard $guard,
        private PromptResolver $resolver,
        private LlmClient $client,
        private AiTokenUsageService $usage,
    ) {}

    /**
     * Generate a feedback string for a failed practice attempt.
     *
     * @throws AiLimitExceededException when a daily token budget is exhausted — before any LLM call
     */
    public function generateFeedback(FeedbackInput $input, User $user): string
    {
        $this->guard->ensureQuotaAvailable($user);

        $system = $this->resolver->resolve($input->coursePrompt);
        $userMessage = $this->buildFeedbackMessage($input);

        $response = $this->client->complete($system, $userMessage);

        $this->usage->recordUsage(
            $user,
            $response->tokensUsed,
            AiTokenUsageAction::Feedback,
            (string) (config('ai.model') ?? 'dummy'),
        );

        return $response->content;
    }

    /**
     * Fill the feedback template with the attempt context.
     */
    private function buildFeedbackMessage(FeedbackInput $input): string
    {
        return str_replace(
            ['{task}', '{expected}', '{submitted}', '{error}'],
            [$input->taskText, $input->expectedResult, $input->submittedSolution, $input->errorMessage],
            self::FEEDBACK_MESSAGE_TEMPLATE,
        );
    }
}
