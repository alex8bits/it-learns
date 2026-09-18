<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\AdminAuditAction;
use App\Enums\AiTokenUsageAction;
use App\Models\Course;
use App\Models\User;
use App\Services\Admin\AdminAuditLogger;
use App\Services\Ai\AiLimitExceededException;
use App\Services\Ai\AiLimitGuard;
use App\Services\Ai\AiTokenUsageService;
use App\Services\Ai\LlmClient;
use App\Services\Ai\PromptResolver;
use Illuminate\Support\Facades\DB;

/**
 * Prompt playground (Stage 11): a live test call against the active
 * LlmClient, mirroring the student AI pipeline (AiFeedbackService):
 * quota guard -> prompt resolution -> LlmClient -> usage recording.
 * The token spend and the audit row are written in one transaction
 * (two tables, rule #6); admins get no quota exemption (design D-A).
 */
class RunPromptPlayground
{
    public function __construct(
        private AiLimitGuard $guard,
        private PromptResolver $resolver,
        private LlmClient $client,
        private AiTokenUsageService $usage,
        private AdminAuditLogger $audit,
    ) {}

    /**
     * Run the playground call and return what the admin should see: the
     * model answer plus the spend and the assembled system prompt.
     *
     * @return array{content: string, tokens_used: int, model: string, system_prompt: string}
     *
     * @throws AiLimitExceededException when the admin's daily token budget is exhausted — before any LLM call
     */
    public function execute(User $admin, string $userMessage, ?Course $course): array
    {
        $this->guard->ensureQuotaAvailable($admin);

        $system = $this->resolver->resolve($course?->ai_course_prompt);

        $response = $this->client->complete($system, $userMessage);

        $model = (string) (config('ai.model') ?? 'dummy');

        DB::transaction(function () use ($admin, $course, $userMessage, $system, $response, $model): void {
            $this->usage->recordUsage($admin, $response->tokensUsed, AiTokenUsageAction::Playground, $model);

            $this->audit->log(AdminAuditAction::PromptPlaygroundRun, $course, [
                'course_id' => $course?->id,
                'system_prompt_chars' => mb_strlen($system),
                'user_message_chars' => mb_strlen($userMessage),
                'tokens_used' => $response->tokensUsed,
                'model' => $model,
            ]);
        });

        return [
            'content' => $response->content,
            'tokens_used' => $response->tokensUsed,
            'model' => $model,
            'system_prompt' => $system,
        ];
    }
}
