<?php

declare(strict_types=1);

namespace App\Services\Ai\Dto;

/**
 * Input contract of AiFeedbackService::generateFeedback(): everything the
 * LLM needs to explain a failed practice attempt. Built from primitives by
 * design (user decision 2026-09-14) — Stage 8 constructs it from a
 * PracticeTaskSubmission via a thin wrapper.
 */
final readonly class FeedbackInput
{
    /**
     * @param  string  $taskText  text of the practice task the student attempted
     * @param  string  $expectedResult  the expected result the submission is compared against
     * @param  string  $submittedSolution  the code/query of the failed attempt
     * @param  string  $errorMessage  execution/comparison error of the attempt
     * @param  string|null  $coursePrompt  course-refining prompt appended to the global system prompt; Stage 6+ passes `courses.ai_course_prompt`
     */
    public function __construct(
        public string $taskText,
        public string $expectedResult,
        public string $submittedSolution,
        public string $errorMessage,
        public ?string $coursePrompt = null,
    ) {}
}
