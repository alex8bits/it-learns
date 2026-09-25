<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\Level;
use App\Models\TheoryTask;
use App\Models\User;
use Tests\TestCase;

/**
 * Feature smoke для student-flow `POST /theory-tasks/{task}/answer`
 * (Этап 7 фичи «theory-counter-and-correct-answer»). Закрывает
 * регрессионный гэп, упомянутый в
 * `.mavis/design/2026-09-17-lesson-staged-flow.md:96` — до сих пор
 * HTTP-граница student-side ответа на теорию не была покрыта.
 *
 * Happy-path + ключевые 4xx: FormRequest 422 (foreign-option),
 * 404 (unpublished), 401/redirect (guest).
 */
class TheoryAnswerTest extends TestCase
{
    public function test_correct_answer_redirects_back_with_flash(): void
    {
        $user = User::factory()->create();
        $lesson = $this->createPublishedLesson();
        $task = TheoryTask::factory()->withOptions()->for($lesson)->create(['order' => 1]);
        $correct = $task->options->firstWhere('is_correct', true);

        $response = $this->actingAs($user)
            ->from(route('lessons.show', $lesson->slug))
            ->post(route('theory-tasks.answer', $task), ['option_id' => $correct->id]);

        $response->assertRedirect(route('lessons.show', $lesson->slug));
        $response->assertSessionHas('theory_feedback.task_id', $task->id);
        $response->assertSessionHas('theory_feedback.is_correct', true);
        // `error_text` is null for a correct pick — session->has() with
        // a null nested value short-circuits to false (Arr::has skips
        // null branches), so we read the value directly instead.
        $this->assertNull(
            session()->get('theory_feedback.error_text'),
            'Верный ответ не должен нести error_text.',
        );
    }

    public function test_wrong_answer_returns_error_text_in_flash(): void
    {
        $user = User::factory()->create();
        $lesson = $this->createPublishedLesson();
        $task = TheoryTask::factory()->withOptions()->for($lesson)->create(['order' => 1]);
        $wrong = $task->options->firstWhere('is_correct', false);

        $response = $this->actingAs($user)
            ->from(route('lessons.show', $lesson->slug))
            ->post(route('theory-tasks.answer', $task), ['option_id' => $wrong->id]);

        $response->assertRedirect(route('lessons.show', $lesson->slug));
        $response->assertSessionHas('theory_feedback.is_correct', false);
        $response->assertSessionHas('theory_feedback.error_text', $wrong->error_text);
    }

    public function test_option_of_another_task_returns_422(): void
    {
        $user = User::factory()->create();
        $lesson = $this->createPublishedLesson();
        $taskA = TheoryTask::factory()->withOptions()->for($lesson)->create(['order' => 1]);
        $taskB = TheoryTask::factory()->withOptions()->for($lesson)->create(['order' => 2]);
        $optionOfB = $taskB->options->first();

        // Пытаемся отправить опцию из taskB в endpoint taskA: scoped
        // Exists-rule в AnswerTheoryTaskRequest отбрасывает запрос
        // ещё до Action-слоя. Inertia-форма возвращает redirect-back с
        // одноразовыми ошибками в сессии (HTTP-семантика 422 скрыта
        // за UX-friendly redirect).
        $response = $this->actingAs($user)
            ->post(route('theory-tasks.answer', $taskA), ['option_id' => $optionOfB->id]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('option_id');
    }

    public function test_unpublished_task_returns_404(): void
    {
        $user = User::factory()->create();
        $lesson = $this->createPublishedLesson();
        $task = TheoryTask::factory()->unpublished()->withOptions()->for($lesson)->create();
        $option = $task->options->first();

        $response = $this->actingAs($user)
            ->post(route('theory-tasks.answer', $task), ['option_id' => $option->id]);

        $response->assertNotFound();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $lesson = $this->createPublishedLesson();
        $task = TheoryTask::factory()->withOptions()->for($lesson)->create(['order' => 1]);
        $option = $task->options->first();

        $response = $this->post(route('theory-tasks.answer', $task), ['option_id' => $option->id]);

        // Authenticate middleware (Stage 7, design A1a) отправляет
        // полностраничный POST гостя на /login.
        $response->assertRedirect(route('login'));
    }

    /**
     * A published course -> level -> lesson chain. The answer flow guards
     * the whole triple (see AnswerTheoryTask::execute).
     */
    private function createPublishedLesson(): Lesson
    {
        $course = Course::factory()->published()->create();
        $level = Level::factory()->for($course)->create(['order' => 1]);

        return Lesson::factory()->for($level)->create(['order' => 1]);
    }
}
