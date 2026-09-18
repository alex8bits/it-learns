<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\Setting;

/**
 * Assembles the final system prompt for an LLM call: the active global
 * prompt (read from the `settings` cache row) optionally refined by a
 * course-specific prompt passed by the caller.
 *
 * In Stage 4 the course text arrives as a parameter (scenario-service
 * DTOs); from Stage 6 callers pass `courses.ai_course_prompt` through.
 */
class PromptResolver
{
    /**
     * Resolve the system prompt: `global + "\n\n" + course` when both are
     * non-empty, whichever exists when only one is, and an empty string
     * when neither is. A missing `settings` row counts as an empty global
     * prompt, never as an error.
     */
    public function resolve(?string $coursePrompt = null): string
    {
        $setting = Setting::findByKey(PromptKeys::GLOBAL_SYSTEM_PROMPT);
        $global = $setting === null ? '' : (string) $setting->value;

        if ($global === '') {
            return $coursePrompt ?? '';
        }

        if ($coursePrompt === null || $coursePrompt === '') {
            return $global;
        }

        return $global."\n\n".$coursePrompt;
    }
}
