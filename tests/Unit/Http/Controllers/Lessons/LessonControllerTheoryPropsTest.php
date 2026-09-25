<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Controllers\Lessons;

use App\Http\Controllers\Lessons\LessonController;
use App\Models\TheoryTask;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Unit-тест приватного маппера `LessonController::buildCorrectOptionFor`
 * (Task 01 фичи «theory-counter-and-correct-answer»): собирает `{id, text}`
 * правильной опции только для уже решённых верно задач. Это **единственный**
 * юнит-тест метода — покрывает все 4 ветки:
 *
 *  - задача без пользовательского ответа -> null;
 *  - пользовательский ответ есть, но is_correct === false -> null;
 *  - пользовательский ответ верный, корректная опция существует -> {id, text};
 *  - пользовательский ответ верный, но корректная опция пропала
 *    (admin-edit cascade) -> null.
 */
class LessonControllerTheoryPropsTest extends TestCase
{
    public function test_returns_null_when_task_has_no_user_answer(): void
    {
        $task = TheoryTask::factory()->withOptions()->create();

        $result = $this->invokeBuildCorrectOptionFor($task, []);

        $this->assertNull($result);
    }

    public function test_returns_null_when_user_answer_is_wrong(): void
    {
        $task = TheoryTask::factory()->withOptions()->create();
        $wrong = $task->options->firstWhere('is_correct', false);
        $userAnswers = [$task->id => ['option_id' => $wrong->id, 'is_correct' => false]];

        $result = $this->invokeBuildCorrectOptionFor($task, $userAnswers);

        $this->assertNull($result);
    }

    public function test_returns_correct_option_when_user_answered_correctly(): void
    {
        $task = TheoryTask::factory()->withOptions()->create();
        $correct = $task->options->firstWhere('is_correct', true);
        $userAnswers = [$task->id => ['option_id' => $correct->id, 'is_correct' => true]];

        $result = $this->invokeBuildCorrectOptionFor($task, $userAnswers);

        $this->assertNotNull($result);
        $this->assertSame(['id' => $correct->id, 'text' => $correct->text], $result);
    }

    public function test_returns_null_when_correct_option_was_cascade_deleted(): void
    {
        // Имитация admin-edit cascade: правильная опция пропала, но в
        // user_theory_task_answers уже зафиксирован ответ с её id и
        // is_correct=true. Маппер должен вернуть null — корректной
        // опции больше нет, показывать нечего.
        $task = TheoryTask::factory()->withOptions()->create();
        $correct = $task->options->firstWhere('is_correct', true);
        $userAnswers = [$task->id => ['option_id' => $correct->id, 'is_correct' => true]];

        $task->options()->update(['is_correct' => false]);
        $task->refresh();

        $result = $this->invokeBuildCorrectOptionFor($task, $userAnswers);

        $this->assertNull($result);
    }

    /**
     * Invoke the private mapper on a fresh controller instance.
     *
     * @param  array<int, array{option_id: int, is_correct: bool}>  $userAnswers
     * @return array{id: int, text: string}|null
     */
    private function invokeBuildCorrectOptionFor(TheoryTask $task, array $userAnswers): ?array
    {
        $controller = new LessonController;
        $reflection = new ReflectionMethod($controller, 'buildCorrectOptionFor');
        $reflection->setAccessible(true);

        return $reflection->invoke($controller, $task, $userAnswers);
    }
}
