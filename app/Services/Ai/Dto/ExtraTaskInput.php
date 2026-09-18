<?php

declare(strict_types=1);

namespace App\Services\Ai\Dto;

/**
 * Input contract of AiTaskGeneratorService::generateExtraTask(): the task
 * the student just solved, used as the style/topic reference for a new one.
 * Built from primitives by design (user decision 2026-09-14).
 */
final readonly class ExtraTaskInput
{
    /**
     * @param  string  $taskText  text of the reference practice task
     * @param  string  $expectedResult  the expected result of the reference task
     * @param  string|null  $coursePrompt  course-refining prompt appended to the global system prompt; Stage 6+ passes `courses.ai_course_prompt`
     */
    public function __construct(
        public string $taskText,
        public string $expectedResult,
        public ?string $coursePrompt = null,
    ) {}
}
