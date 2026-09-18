<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * Canonical prompt keys used across the AI layer (`ai_prompt_versions`,
 * the `settings` cache row and the admin history UI).
 *
 * Course-scoped keys are served by PromptVersionService from Stage 4, but
 * their cache target (`courses.ai_course_prompt`) is only wired up in
 * Stage 6, when the courses table gains the column.
 */
final class PromptKeys
{
    public const string GLOBAL_SYSTEM_PROMPT = 'ai.global_system_prompt';

    /**
     * Build the versioned prompt key of a course's clarifying prompt.
     */
    public static function forCourse(int $courseId): string
    {
        return "course.{$courseId}.ai_course_prompt";
    }
}
