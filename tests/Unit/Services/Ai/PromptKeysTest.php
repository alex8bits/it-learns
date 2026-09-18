<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai;

use App\Services\Ai\PromptKeys;
use PHPUnit\Framework\TestCase;

class PromptKeysTest extends TestCase
{
    public function test_global_system_prompt_constant_value(): void
    {
        $this->assertSame('ai.global_system_prompt', PromptKeys::GLOBAL_SYSTEM_PROMPT);
    }

    public function test_for_course_builds_course_scoped_key(): void
    {
        $this->assertSame('course.7.ai_course_prompt', PromptKeys::forCourse(7));
    }

    public function test_for_course_separates_keys_of_different_courses(): void
    {
        $this->assertNotSame(PromptKeys::forCourse(7), PromptKeys::forCourse(8));
    }
}
