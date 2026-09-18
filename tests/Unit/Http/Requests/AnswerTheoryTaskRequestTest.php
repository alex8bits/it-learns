<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Requests;

use App\Http\Requests\AnswerTheoryTaskRequest;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Level;
use App\Models\TheoryTask;
use App\Models\TheoryTaskOption;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Exists;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AnswerTheoryTaskRequestTest extends TestCase
{
    private TheoryTask $task;

    private TheoryTaskOption $correctOption;

    private TheoryTaskOption $foreignOption;

    protected function setUp(): void
    {
        parent::setUp();

        $course = Course::factory()->published()->create();
        $level = Level::factory()->for($course)->create([
            'order' => 1,
        ]);
        $lesson = Lesson::factory()->for($level)->create();

        $this->task = TheoryTask::factory()->for($lesson)->withOptions()->create();
        $this->correctOption = $this->task->options()->where('is_correct', true)->firstOrFail();

        $foreignTask = TheoryTask::factory()->for($lesson)->withOptions()->create();
        $this->foreignOption = $foreignTask->options()->firstOrFail();
    }

    public function test_authorize_returns_true(): void
    {
        $this->assertTrue($this->makeRequest()->authorize());
    }

    public function test_rules_require_existing_option_of_the_route_task(): void
    {
        $rules = $this->makeRequest()->rules();

        $this->assertIsArray($rules['option_id']);
        $this->assertSame('required', $rules['option_id'][0]);
        $this->assertSame('integer', $rules['option_id'][1]);
        $this->assertInstanceOf(Exists::class, $rules['option_id'][2]);
    }

    public function test_messages_are_human_readable(): void
    {
        $messages = $this->makeRequest()->messages();

        $this->assertSame('Выберите вариант ответа', $messages['option_id.required']);
        $this->assertSame('Вариант ответа должен быть числом', $messages['option_id.integer']);
        $this->assertSame('Этот вариант не относится к текущему вопросу', $messages['option_id.exists']);
    }

    public function test_option_of_the_route_task_passes(): void
    {
        $validator = Validator::make(
            ['option_id' => $this->correctOption->id],
            $this->makeRequest()->rules(),
        );

        $this->assertTrue($validator->passes());
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function invalidPayloadProvider(): array
    {
        return [
            'option_id missing' => [[]],
            'option_id is not an integer' => [['option_id' => 'first']],
            'option_id is unknown to the table' => [['option_id' => 999999999]],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[DataProvider('invalidPayloadProvider')]
    public function test_invalid_payload_fails_with_option_id_error(array $data): void
    {
        $validator = Validator::make(
            $data,
            $this->makeRequest()->rules(),
        );

        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has('option_id'));
    }

    public function test_option_of_another_task_fails_exists_where_check(): void
    {
        $validator = Validator::make(
            ['option_id' => $this->foreignOption->id],
            $this->makeRequest()->rules(),
        );

        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has('option_id'));
    }

    /**
     * The FormRequest reads the bound {task} route parameter inside
     * rules(); a stand-in route is injected as the route resolver with
     * the parameter pre-set — the same contract FormRequest::route()
     * relies on (`$route->parameter('task')`).
     */
    private function makeRequest(): AnswerTheoryTaskRequest
    {
        $request = new AnswerTheoryTaskRequest;
        $request->setRouteResolver(function () {
            return new class($this->task)
            {
                public function __construct(private readonly TheoryTask $task) {}

                public function parameter(string $name, ?TheoryTask $default = null): ?TheoryTask
                {
                    return $name === 'task' ? $this->task : $default;
                }
            };
        });

        return $request;
    }
}
