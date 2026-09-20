<?php

declare(strict_types=1);

namespace App\Http\Controllers\Lessons;

use App\Enums\CourseStatus;
use App\Enums\LessonProgressStatus;
use App\Enums\PracticeAttemptStatus;
use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\PracticeTask;
use App\Models\PracticeTaskSubmission;
use App\Models\TheoryTask;
use App\Models\TheoryTaskOption;
use App\Models\UserLessonProgress;
use App\Models\UserTheoryTaskAnswer;
use App\Services\Courses\NextLessonResolver;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Lesson page (Stage 7): study material plus the theory quiz, followed
 * by the SQL practice tasks (Stage 8). The route lives in the auth
 * group — guests never reach it (302 to login via the platform
 * Authenticate middleware). Visibility follows the public course card
 * semantics: a published lesson of a published course only, anything
 * else is a 404.
 */
class LessonController extends Controller
{
    public function show(Request $request, string $slug): Response
    {
        $lesson = Lesson::query()
            ->published()
            ->where('slug', $slug)
            ->with([
                'theoryTasks' => fn ($tasks) => $tasks->published()->with([
                    'options' => fn ($options) => $options->orderBy('order'),
                ]),
                // Ordering lives in the relation itself (order column).
                'practiceTasks' => fn ($tasks) => $tasks->published(),
                'level.course' => fn ($course) => $course->select('id', 'slug', 'title', 'status'),
            ])
            ->firstOrFail();

        $course = $lesson->level->course;
        abort_unless($course->status === CourseStatus::Published, 404);

        $user = $request->user();

        return Inertia::render('Lessons/Show', [
            'lesson' => [
                'id' => $lesson->id,
                'slug' => $lesson->slug,
                'title' => $lesson->title,
                'material' => $lesson->material,
                'theoryTasks' => $lesson->theoryTasks
                    ->map(fn (TheoryTask $task): array => [
                        'id' => $task->id,
                        'question' => $task->question,
                        'order' => $task->order,
                        // Manual mapping is the quiz-spoiler guard: only
                        // {id, text} leave the server, never is_correct /
                        // error_text (design Risk «Утечка is_correct»).
                        'options' => $task->options
                            ->map(fn (TheoryTaskOption $option): array => [
                                'id' => $option->id,
                                'text' => $option->text,
                            ])
                            ->values()
                            ->all(),
                    ])
                    ->values()
                    ->all(),
            ],
            'course' => [
                // `id` lets the page build preview links for the admin
                // course preview (Stage 11); the user flow keeps using
                // the slug.
                'id' => $course->id,
                'slug' => $course->slug,
                'title' => $course->title,
            ],
            'answers' => $this->userAnswers($user->id, $lesson),
            'lessonStatus' => $this->lessonStatus($user->id, $lesson),
            'feedback' => $request->session()->get('theory_feedback'),
            'practiceTasks' => $lesson->practiceTasks
                ->map(fn (PracticeTask $task): array => [
                    'id' => $task->id,
                    'statement' => $task->statement,
                    'expected_result_text' => $task->expected_result_text,
                    'order' => $task->order,
                    // Manual mapping is the practice-spoiler guard (the
                    // mirror of the options guard above): only
                    // {id, statement, expected_result_text, order} leave
                    // the server, never seed_sql / expected_hash /
                    // expected_rows (design Risk «Spoiler-гвард»).
                ])
                ->values()
                ->all(),
            'passedPracticeTaskIds' => $this->passedPracticeTaskIds($user->id, $lesson),
            'practiceFeedback' => $request->session()->get('practice_feedback'),
            // Премиум ИИ-флоу (Этап 8): одноразовые flash'и ИИ-роутов —
            // фидбэк по неудачной попытке и сгенерированная доп. задача
            // (персистится только фидбэк; доп. задача — только показ).
            'aiFeedback' => $request->session()->get('ai_feedback'),
            'extraTask' => $request->session()->get('extra_task'),
            // Эффективные пороги минимума: min(K, N) по опубликованным
            // задачам (коллекции уже загружены выше — без доп. запросов).
            // Фронт выводит из них theoryMinimumDone/practiceMinimumDone
            // (UI-гейт), серверный авторитет завершённости —
            // LessonCompletionChecker.
            'requiredTheoryCount' => min(
                (int) config('progress.theory_required_per_lesson', 3),
                $lesson->theoryTasks->count(),
            ),
            'requiredPracticeCount' => min(
                (int) config('progress.practice_required_per_lesson', 1),
                $lesson->practiceTasks->count(),
            ),
            // Следующий урок курса для кнопки «Перейти к следующему
            // уроку»; null — урок последний. Только {id, slug, title}.
            'nextLesson' => ($next = $this->nextLesson($course, $lesson)) !== null
                ? ['id' => $next->id, 'slug' => $next->slug, 'title' => $next->title]
                : null,
        ]);
    }

    /**
     * The user's latest answers to the published tasks of this lesson as
     * a task_id => {option_id, is_correct} map. `is_correct` here is the
     * user's own recorded outcome (a snapshot of their pick), not the
     * option's hidden correctness flag.
     *
     * @return array<int, array{option_id: int, is_correct: bool}>
     */
    private function userAnswers(int $userId, Lesson $lesson): array
    {
        return UserTheoryTaskAnswer::query()
            ->where('user_id', $userId)
            ->whereIn('theory_task_id', $lesson->theoryTasks->pluck('id'))
            ->get(['theory_task_id', 'option_id', 'is_correct'])
            ->keyBy('theory_task_id')
            ->map(fn (UserTheoryTaskAnswer $answer): array => [
                'option_id' => $answer->option_id,
                'is_correct' => $answer->is_correct,
            ])
            ->all();
    }

    /**
     * The user's progress status of this lesson (enum value) or null —
     * «не начат» is the absence of a progress row, not a case.
     */
    private function lessonStatus(int $userId, Lesson $lesson): ?string
    {
        $progress = UserLessonProgress::query()
            ->where('user_id', $userId)
            ->where('lesson_id', $lesson->id)
            ->first();

        /** @var LessonProgressStatus|null $status */
        $status = $progress?->status;

        return $status?->value;
    }

    /**
     * Ids of the lesson's practice tasks the user has already solved
     * (has at least one Passed attempt) — a single distinct query. The
     * UI opens the first task missing from this list (the sequence
     * gating is a UI-level concern, same as the theory quiz).
     *
     * @return array<int, int>
     */
    private function passedPracticeTaskIds(int $userId, Lesson $lesson): array
    {
        $taskIds = $lesson->practiceTasks->pluck('id');

        if ($taskIds->isEmpty()) {
            return [];
        }

        return PracticeTaskSubmission::query()
            ->where('user_id', $userId)
            ->whereIn('practice_task_id', $taskIds)
            ->where('status', PracticeAttemptStatus::Passed)
            ->distinct()
            ->pluck('practice_task_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * The lesson following $lesson in the course's canonical order
     * (NextLessonResolver). Published only: the user flow never leads
     * into drafts — the route itself 404s on unpublished lessons.
     */
    private function nextLesson(Course $course, Lesson $lesson): ?Lesson
    {
        return app(NextLessonResolver::class)($course, $lesson, publishedOnly: true);
    }
}
