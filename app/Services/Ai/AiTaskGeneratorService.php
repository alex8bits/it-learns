<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Enums\AiTokenUsageAction;
use App\Models\User;
use App\Services\Ai\Dto\ExtraTaskInput;
use App\Services\Ai\Dto\GeneratedExtraTask;

/**
 * Scenario service: generates an extra practice task in the same style
 * and topic as a solved one.
 *
 * The pipeline is fixed and shared with AiFeedbackService (the AI seam
 * of Stage 4): quota guard -> prompt resolution -> LlmClient -> usage
 * recording. Premium is not checked here — Stage 8 hangs EnsurePremium
 * on the HTTP routes that call this service.
 */
class AiTaskGeneratorService
{
    /**
     * Fixed delimiters the template demands from the model and the
     * response is parsed by.
     */
    private const TASK_MARKER = '=== TASK ===';

    private const EXPECTED_MARKER = '=== EXPECTED ===';

    /**
     * User-message template. Placeholders `{task}` and `{expected}` are
     * filled via str_replace; the template also fixes the marker format
     * the answer must follow to be parsable.
     */
    private const EXTRA_TASK_MESSAGE_TEMPLATE = <<<'TXT'
    Сгенерируй дополнительное задание в том же стиле и теме.

    Задание:
    {task}

    Ожидаемый результат:
    {expected}

    Ответ строго в формате:
    === TASK ===
    <текст нового задания>
    === EXPECTED ===
    <ожидаемый результат нового задания>
    TXT;

    public function __construct(
        private AiLimitGuard $guard,
        private PromptResolver $resolver,
        private LlmClient $client,
        private AiTokenUsageService $usage,
    ) {}

    /**
     * Generate an extra task based on the reference one.
     *
     * @throws AiLimitExceededException when a daily token budget is exhausted — before any LLM call
     */
    public function generateExtraTask(ExtraTaskInput $input, User $user): GeneratedExtraTask
    {
        $this->guard->ensureQuotaAvailable($user);

        $system = $this->resolver->resolve($input->coursePrompt);
        $userMessage = $this->buildExtraTaskMessage($input);

        $response = $this->client->complete($system, $userMessage);

        $this->usage->recordUsage(
            $user,
            $response->tokensUsed,
            AiTokenUsageAction::ExtraTask,
            (string) (config('ai.model') ?? 'dummy'),
        );

        return $this->parseResponse($response->content);
    }

    /**
     * Fill the generation template with the reference task.
     */
    private function buildExtraTaskMessage(ExtraTaskInput $input): string
    {
        return str_replace(
            ['{task}', '{expected}'],
            [$input->taskText, $input->expectedResult],
            self::EXTRA_TASK_MESSAGE_TEMPLATE,
        );
    }

    /**
     * Split the answer into task/expected sections by the fixed markers.
     *
     * Degrades without an exception when the model ignored the format
     * (a marker missing or the expected marker preceding the task one):
     * the whole text lands in `taskText`, `expectedResult` is an empty
     * string.
     */
    private function parseResponse(string $content): GeneratedExtraTask
    {
        $taskPos = strpos($content, self::TASK_MARKER);
        $expectedPos = strpos($content, self::EXPECTED_MARKER);

        if ($taskPos === false || $expectedPos === false || $expectedPos < $taskPos) {
            return new GeneratedExtraTask(taskText: trim($content), expectedResult: '');
        }

        $taskStart = $taskPos + strlen(self::TASK_MARKER);
        $expectedStart = $expectedPos + strlen(self::EXPECTED_MARKER);

        return new GeneratedExtraTask(
            taskText: trim(substr($content, $taskStart, $expectedPos - $taskStart)),
            expectedResult: trim(substr($content, $expectedStart)),
        );
    }
}
