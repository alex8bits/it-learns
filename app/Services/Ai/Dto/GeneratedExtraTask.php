<?php

declare(strict_types=1);

namespace App\Services\Ai\Dto;

/**
 * Output contract of AiTaskGeneratorService::generateExtraTask(): the
 * generated task and its expected result, parsed from the LLM answer.
 */
final readonly class GeneratedExtraTask
{
    /**
     * @param  string  $taskText  text of the generated extra task
     * @param  string  $expectedResult  expected result of the generated task (empty string when the model ignored the marker format)
     */
    public function __construct(
        public string $taskText,
        public string $expectedResult,
    ) {}
}
