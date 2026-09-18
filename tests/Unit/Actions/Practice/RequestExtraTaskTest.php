<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Practice;

use App\Actions\Practice\RequestExtraTask;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Level;
use App\Models\PracticeTask;
use App\Models\User;
use App\Services\Ai\AiLimitExceededException;
use App\Services\Ai\AiTaskGeneratorService;
use App\Services\Ai\Dto\ExtraTaskInput;
use App\Services\Ai\Dto\GeneratedExtraTask;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class RequestExtraTaskTest extends TestCase
{
    private const COURSE_PROMPT = 'Отвечай в контексте курса SQL для новичков.';

    private User $user;

    private PracticeTask $task;

    /** The ExtraTaskInput captured from the mocked service. */
    private ?ExtraTaskInput $capturedInput = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $course = Course::factory()->published()->create([
            'ai_course_prompt' => self::COURSE_PROMPT,
        ]);
        $level = Level::factory()->for($course)->create();
        $lesson = Lesson::factory()->for($level)->create();
        $this->task = PracticeTask::factory()->for($lesson)->create();
    }

    public function test_builds_the_exact_extra_task_input_and_returns_the_generated_task(): void
    {
        $generated = new GeneratedExtraTask(
            taskText: 'Выведите топ-3 самых новых книг',
            expectedResult: 'Три строки, новые первыми',
        );

        $service = $this->mockService();
        $service->shouldReceive('generateExtraTask')->once()
            ->andReturnUsing(function (ExtraTaskInput $input, User $user) use ($generated): GeneratedExtraTask {
                $this->capturedInput = $input;
                $this->assertSame($this->user->id, $user->id);

                return $generated;
            });

        $result = app(RequestExtraTask::class)->execute($this->user, $this->task);

        $captured = $this->capturedInput;
        $this->assertNotNull($captured);
        $this->assertSame($this->task->statement, $captured->taskText);
        $this->assertSame($this->task->expected_result_text, $captured->expectedResult);
        $this->assertSame(self::COURSE_PROMPT, $captured->coursePrompt);

        $this->assertSame($generated, $result);
    }

    public function test_nothing_is_persisted(): void
    {
        $this->mockService()->shouldReceive('generateExtraTask')->once()
            ->andReturn(new GeneratedExtraTask(taskText: 'Новая задача', expectedResult: 'Ожидание'));

        app(RequestExtraTask::class)->execute($this->user, $this->task);

        // The generated task is shown to the student only (design
        // non-goal: no persistence) — no new rows anywhere.
        $this->assertDatabaseCount('practice_tasks', 1);
        $this->assertDatabaseCount('practice_task_submissions', 0);
        $this->assertDatabaseCount('practice_task_feedbacks', 0);
        $this->assertDatabaseCount('ai_token_usages', 0);
    }

    public function test_ai_limit_exception_bubbles(): void
    {
        $this->mockService()->shouldReceive('generateExtraTask')->once()
            ->andThrow(new AiLimitExceededException(isGlobalLimit: true));

        try {
            app(RequestExtraTask::class)->execute($this->user, $this->task);
            $this->fail('Expected AiLimitExceededException to bubble out of the action.');
        } catch (AiLimitExceededException $exception) {
            $this->assertTrue($exception->isGlobalLimit);
        }
    }

    /**
     * Bind a mocked AiTaskGeneratorService (the AiFeedbackServiceTest
     * pattern with LlmClient) and return the mock for per-test
     * expectations.
     */
    private function mockService(): MockInterface
    {
        $service = Mockery::mock(AiTaskGeneratorService::class);
        $this->instance(AiTaskGeneratorService::class, $service);

        return $service;
    }
}
