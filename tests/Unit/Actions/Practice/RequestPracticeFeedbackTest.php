<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Practice;

use App\Actions\Practice\RequestPracticeFeedback;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Level;
use App\Models\PracticeTask;
use App\Models\PracticeTaskFeedback;
use App\Models\PracticeTaskSubmission;
use App\Models\User;
use App\Services\Ai\AiFeedbackService;
use App\Services\Ai\AiLimitExceededException;
use App\Services\Ai\Dto\FeedbackInput;
use Illuminate\Validation\ValidationException;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class RequestPracticeFeedbackTest extends TestCase
{
    private const COURSE_PROMPT = 'Отвечай в контексте курса SQL для новичков.';

    private User $user;

    private PracticeTask $task;

    /** The FeedbackInput captured from the mocked service. */
    private ?FeedbackInput $capturedInput = null;

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

    public function test_missing_submission_throws_404(): void
    {
        $this->mockService()->shouldReceive('generateFeedback')->never();

        try {
            $this->requestFeedback();
            $this->fail('Expected NotFoundHttpException when the user has no attempt.');
        } catch (NotFoundHttpException) {
            // Expected: 404 semantics — there is nothing to explain.
        }

        $this->assertDatabaseCount('practice_task_feedbacks', 0);
    }

    public function test_passed_latest_submission_throws_validation_exception(): void
    {
        PracticeTaskSubmission::factory()->for($this->task)->for($this->user)->passed()->create();
        $this->mockService()->shouldReceive('generateFeedback')->never();

        try {
            $this->requestFeedback();
            $this->fail('Expected ValidationException for a Passed attempt.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['Фидбэк доступен только после неудачной попытки.'],
                $exception->errors()['submission'],
            );
        }

        $this->assertDatabaseCount('practice_task_feedbacks', 0);
    }

    public function test_the_latest_attempt_wins_over_an_older_failed_one(): void
    {
        PracticeTaskSubmission::factory()->for($this->task)->for($this->user)
            ->failed()->create(['created_at' => now()->subHour()]);
        PracticeTaskSubmission::factory()->for($this->task)->for($this->user)
            ->passed()->create(['created_at' => now()]);
        $this->mockService()->shouldReceive('generateFeedback')->never();

        $this->expectException(ValidationException::class);

        $this->requestFeedback();
    }

    public function test_failed_attempt_builds_the_exact_feedback_input_and_persists_the_row(): void
    {
        $submission = PracticeTaskSubmission::factory()->for($this->task)->for($this->user)
            ->failed()->create(['code' => 'SELECT title FROM books']);

        $this->mockService()->shouldReceive('generateFeedback')->once()
            ->andReturnUsing(function (FeedbackInput $input, User $user): string {
                $this->capturedInput = $input;
                $this->assertSame($this->user->id, $user->id);

                return 'ИИ: запрос выбирает только title, а нужны и годы.';
            });

        $feedback = $this->requestFeedback();

        $captured = $this->capturedInput;
        $this->assertNotNull($captured);
        $this->assertSame($this->task->statement, $captured->taskText);
        $this->assertSame($this->task->expected_result_text, $captured->expectedResult);
        $this->assertSame('SELECT title FROM books', $captured->submittedSolution);
        // A Failed attempt has no execution error — the fallback message
        // tells the model what actually went wrong (result mismatch).
        $this->assertSame('Результат не совпал с эталоном', $captured->errorMessage);
        $this->assertSame(self::COURSE_PROMPT, $captured->coursePrompt);

        $this->assertInstanceOf(PracticeTaskFeedback::class, $feedback);
        $this->assertSame('ИИ: запрос выбирает только title, а нужны и годы.', $feedback->body);
        $this->assertDatabaseHas('practice_task_feedbacks', [
            'practice_task_submission_id' => $submission->id,
            'user_id' => $this->user->id,
            'body' => 'ИИ: запрос выбирает только title, а нужны и годы.',
        ]);
    }

    public function test_error_attempt_error_text_becomes_the_error_message(): void
    {
        PracticeTaskSubmission::factory()->for($this->task)->for($this->user)
            ->error()->create(['created_at' => now()]);

        $this->mockService()->shouldReceive('generateFeedback')->once()
            ->andReturnUsing(function (FeedbackInput $input): string {
                $this->capturedInput = $input;

                return 'feedback';
            });

        $this->requestFeedback();

        $captured = $this->capturedInput;
        $this->assertNotNull($captured);
        $this->assertSame('SQLite: no such table: books', $captured->errorMessage);
    }

    public function test_ai_limit_exception_bubbles_and_writes_nothing(): void
    {
        PracticeTaskSubmission::factory()->for($this->task)->for($this->user)
            ->failed()->create(['created_at' => now()]);

        $this->mockService()->shouldReceive('generateFeedback')->once()
            ->andThrow(new AiLimitExceededException(isGlobalLimit: false));

        try {
            $this->requestFeedback();
            $this->fail('Expected AiLimitExceededException to bubble out of the action.');
        } catch (AiLimitExceededException $exception) {
            $this->assertFalse($exception->isGlobalLimit);
        }

        // The LLM call runs before the write: a refused budget leaves no
        // feedback row behind.
        $this->assertDatabaseCount('practice_task_feedbacks', 0);
    }

    public function test_failure_inside_the_transaction_writes_nothing(): void
    {
        PracticeTaskSubmission::factory()->for($this->task)->for($this->user)
            ->failed()->create(['created_at' => now()]);

        $this->mockService()->shouldReceive('generateFeedback')->once()->andReturn('feedback');

        PracticeTaskFeedback::creating(function (): void {
            throw new RuntimeException('Feedback insert failed.');
        });

        try {
            $this->requestFeedback();
            $this->fail('Expected the insert failure to bubble up.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Feedback insert failed.', $exception->getMessage());
        }

        $this->assertDatabaseCount('practice_task_feedbacks', 0);
    }

    private function requestFeedback(): PracticeTaskFeedback
    {
        return app(RequestPracticeFeedback::class)->execute($this->user, $this->task);
    }

    /**
     * Bind a mocked AiFeedbackService (the AiFeedbackServiceTest pattern
     * with LlmClient) and return the mock for per-test expectations.
     */
    private function mockService(): MockInterface
    {
        $service = Mockery::mock(AiFeedbackService::class);
        $this->instance(AiFeedbackService::class, $service);

        return $service;
    }
}
