<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\Level;
use App\Models\TheoryTask;
use App\Models\User;
use App\Models\UserTheoryTaskAnswer;
use Tests\TestCase;

/**
 * Feature smoke для student-flow `GET /lessons/{slug}`
 * (Этап 7 фичи «theory-counter-and-correct-answer»). Закрывает
 * регрессионный гэп, упомянутый в
 * `.mavis/design/2026-09-17-lesson-staged-flow.md:96` — student-side
 * ассертов на шейп props теории до сих пор не было. Проверяем
 * spoiler-гвард `is_correct`/`error_text` опции и появление
 * `correct_option` только для верно решённых задач.
 */
class LessonShowTest extends TestCase
{
    public function test_shows_correct_option_only_for_correctly_answered_tasks(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->published()->create();
        $level = Level::factory()->for($course)->create(['order' => 1]);
        $lesson = Lesson::factory()->for($level)->create(['order' => 1]);

        $task1 = TheoryTask::factory()->withOptions()->for($lesson)->create(['order' => 1]);
        $task2 = TheoryTask::factory()->withOptions()->for($lesson)->create(['order' => 2]);
        $task3 = TheoryTask::factory()->withOptions()->for($lesson)->create(['order' => 3]);

        $correct1 = $task1->options->firstWhere('is_correct', true);
        $wrong2 = $task2->options->firstWhere('is_correct', false);

        // task1 решена верно -> должна показать correct_option.
        UserTheoryTaskAnswer::create([
            'user_id' => $user->id,
            'theory_task_id' => $task1->id,
            'option_id' => $correct1->id,
            'is_correct' => true,
            'answered_at' => now(),
        ]);
        // task2 решена неверно -> correct_option === null.
        UserTheoryTaskAnswer::create([
            'user_id' => $user->id,
            'theory_task_id' => $task2->id,
            'option_id' => $wrong2->id,
            'is_correct' => false,
            'answered_at' => now(),
        ]);
        // task3 — без ответа.

        $response = $this->actingAs($user)->get(route('lessons.show', $lesson->slug));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Lessons/Show')
            ->where('lesson.id', $lesson->id)
            ->where('lesson.theoryTasks.0.id', $task1->id)
            ->where('lesson.theoryTasks.1.id', $task2->id)
            ->where('lesson.theoryTasks.2.id', $task3->id)
            // correct_option: только для верно решённой task1.
            ->where('lesson.theoryTasks.0.correct_option.id', $correct1->id)
            ->where('lesson.theoryTasks.0.correct_option.text', $correct1->text)
            ->where('lesson.theoryTasks.1.correct_option', null)
            ->where('lesson.theoryTasks.2.correct_option', null)
            // Spoiler-гвард: option.is_correct НЕ покидает сервер.
            ->missing('lesson.theoryTasks.0.options.0.is_correct')
            ->missing('lesson.theoryTasks.0.options.0.error_text')
            // correct_option НЕ содержит is_correct/error_text (только {id, text}).
            ->missing('lesson.theoryTasks.0.correct_option.is_correct')
            ->missing('lesson.theoryTasks.0.correct_option.error_text')
            // Required window.
            ->where('requiredTheoryCount', 3)
            ->where('answers', [
                $task1->id => ['option_id' => $correct1->id, 'is_correct' => true],
                $task2->id => ['option_id' => $wrong2->id, 'is_correct' => false],
            ]));
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $course = Course::factory()->published()->create();
        $level = Level::factory()->for($course)->create(['order' => 1]);
        $lesson = Lesson::factory()->for($level)->create(['order' => 1]);

        $response = $this->get(route('lessons.show', $lesson->slug));

        // Authenticate middleware (Stage 7, design A1a) отправляет
        // полностраничный визит гостя на /login.
        $response->assertRedirect(route('login'));
    }
}
