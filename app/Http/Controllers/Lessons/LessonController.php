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
use App\Services\Lessons\MaterialRenderer;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
    public function show(Request $request, string $slug, MaterialRenderer $renderer): Response
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

        // Resolved once per request and reused by both the `theoryTasks`
        // mapper (for the `correct_option` field) and the `answers` prop
        // — see buildCorrectOptionFor() for the spoiler-guard exception.
        $userAnswers = $this->userAnswers($user->id, $lesson);

        // The persistent solved-practice review — also the single source
        // of `passedPracticeTaskIds` below (see solvedPracticeTasks()).
        $solved = $this->solvedPracticeTasks($user->id, $lesson);

        return Inertia::render('Lessons/Show', [
            'lesson' => [
                'id' => $lesson->id,
                'slug' => $lesson->slug,
                'title' => $lesson->title,
                'material_html' => $renderer->render($lesson->id, $lesson->material),
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
                        // Narrow spoiler-guard exception: aggregate {id, text}
                        // of the correct option, non-null only for tasks the
                        // user has already answered correctly. See
                        // buildCorrectOptionFor() for the full rationale.
                        'correct_option' => $this->buildCorrectOptionFor($task, $userAnswers),
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
            'answers' => $userAnswers,
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
            'passedPracticeTaskIds' => array_map('intval', array_keys($solved)),
            'solvedPracticeTasks' => $solved,
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
     * Aggregate {id, text} of the correct option for $task — but ONLY if the
     * user has already answered this task correctly. Returns null otherwise:
     * - task has no answer in $userAnswers;
     * - task has an answer with is_correct === false;
     * - the correct option was cascade-deleted (rare, admin option edit).
     *
     * This is a deliberate, narrow exception to the quiz spoiler guard
     * (LessonController::show + docs/concept.md:96-99 +
     * docs/progress-plan.md:180-183): the user has already solved the
     * question — there is no point in hiding the correct answer any
     * more. The spoiler still holds for `options[].is_correct` and for
     * `options[].error_text` — those fields never leave the server.
     *
     * @param  array<int, array{option_id: int, is_correct: bool}>  $userAnswers
     * @return array{id: int, text: string}|null
     */
    private function buildCorrectOptionFor(TheoryTask $task, array $userAnswers): ?array
    {
        $answer = $userAnswers[$task->id] ?? null;
        if ($answer === null || $answer['is_correct'] !== true) {
            return null;
        }

        $correct = $task->options->firstWhere('is_correct', true);
        if ($correct === null) {
            return null;
        }

        return ['id' => $correct->id, 'text' => $correct->text];
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
     * The user's latest Passed submission per practice task of the lesson
     * as a task_id => {code, passed_at, expected_rows} map — the persistent
     * "solved review" data. Only tasks the user has actually solved are
     * present. `expected_rows` is the task's canonical result: a Passed
     * attempt is hash-equal to it (RunPracticeTaskAction::resolveStatus),
     * so the review renders the canonical table without persisting the
     * executed rows. This is the practice-side mirror of the theory
     * `correct_option` spoiler-guard exception: the task is already solved.
     *
     * `passedPracticeTaskIds` is derived from this map's keys — the map
     * is built in the lesson's task order (not the groupBy order), so the
     * prop keeps its semantics: a list of ints in the task order.
     *
     * @return array<int, array{code: string, passed_at: string|null, expected_rows: array<int, array<string, mixed>>}>
     */
    private function solvedPracticeTasks(int $userId, Lesson $lesson): array
    {
        $tasks = $lesson->practiceTasks;

        if ($tasks->isEmpty()) {
            return [];
        }

        $latestPassed = PracticeTaskSubmission::query()
            ->where('user_id', $userId)
            ->whereIn('practice_task_id', $tasks->pluck('id'))
            ->where('status', PracticeAttemptStatus::Passed)
            // Append-only history: the max id IS the latest attempt, so
            // the first row of each groupBy group is the one to show.
            ->orderByDesc('id')
            ->get(['practice_task_id', 'code', 'created_at'])
            ->groupBy('practice_task_id');

        $solved = [];

        foreach ($tasks as $task) {
            $submission = $latestPassed->get($task->id)?->first();

            if ($submission === null) {
                continue;
            }

            /** @var array<int, array<string, mixed>> */
            $expectedRows = $task->expected_rows;

            $solved[$task->id] = [
                'code' => $submission->code,
                // The datetime cast already yields a Carbon instance;
                // Carbon::parse() keeps this correct (and phpstan-honest)
                // for the raw-string shape of the partial-select too.
                'passed_at' => Carbon::parse($submission->created_at)->toISOString(),
                'expected_rows' => $expectedRows,
            ];
        }

        return $solved;
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
