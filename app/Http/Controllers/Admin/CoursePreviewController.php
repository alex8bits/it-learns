<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Courses\CourseController;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\PracticeTask;
use App\Models\TheoryTask;
use App\Models\TheoryTaskOption;
use App\Services\Courses\NextLessonResolver;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Read-only course preview «as a student sees it» (Stage 11, §9.2.3):
 * renders the SAME user-facing Vue pages, but through admin routes and
 * WITHOUT the `published` gates — draft levels/lessons/tasks are
 * visible. Strictly GET-only by construction: no progress or AI POST
 * route exists here, so a preview can never write anything under the
 * admin's user id (and no audit entry is made — rule #17 covers only
 * state-changing operations).
 */
class CoursePreviewController extends Controller
{
    /**
     * Attributes hidden from the preview course card — the same list as
     * {@see CourseController::HIDDEN_COURSE_ATTRIBUTES}
     * (duplicated as a literal because that constant is private; keep
     * the two lists in sync).
     */
    private const HIDDEN_COURSE_ATTRIBUTES = ['ai_course_prompt', 'preview_image_path', 'created_by'];

    /**
     * Course card preview: the user-facing `Courses/Show` page with all
     * levels and lessons eager-loaded (N+1-free), published or not. The
     * admin's own progress is irrelevant to the preview, so `progress`
     * is an explicit null and `previewMode` disables every action.
     */
    public function show(Request $request, Course $course): Response
    {
        $this->authorize('preview', $course);

        $course
            ->load(['levels' => fn ($levels) => $levels->ordered()->with([
                'lessons' => fn ($lessons) => $lessons->ordered(),
            ])])
            ->makeHidden(self::HIDDEN_COURSE_ATTRIBUTES);

        return Inertia::render('Courses/Show', [
            'course' => $course,
            'previewMode' => true,
            'progress' => null,
        ]);
    }

    /**
     * Lesson preview: the user-facing `Lessons/Show` page with the full
     * study material, theory quiz and practice tasks — published or
     * not. The props mirror Lessons/LessonController::show (including
     * the spoiler guards and the completion props
     * `requiredTheoryCount`/`requiredPracticeCount`/`nextLesson`),
     * except everything user-specific is empty and `previewMode`
     * switches the Vue page to read-only rendering.
     */
    public function lesson(Request $request, Course $course, Lesson $lesson): Response
    {
        $lesson->load('level');
        abort_unless($lesson->level->course_id === $course->id, 404);
        $this->authorize('preview', $course);

        $lesson->load([
            'theoryTasks' => fn ($tasks) => $tasks->ordered()->with([
                'options' => fn ($options) => $options->orderBy('order'),
            ]),
            // Ordering lives in the relation itself (order column).
            'practiceTasks' => fn ($tasks) => $tasks->ordered(),
        ]);

        return Inertia::render('Lessons/Show', [
            'lesson' => [
                'id' => $lesson->id,
                'slug' => $lesson->slug,
                'title' => $lesson->title,
                'material' => $lesson->material,
                'is_published' => $lesson->is_published,
                'theoryTasks' => $lesson->theoryTasks
                    ->map(fn (TheoryTask $task): array => [
                        'id' => $task->id,
                        'question' => $task->question,
                        'order' => $task->order,
                        'is_published' => $task->is_published,
                        // Manual mapping is the quiz-spoiler guard: only
                        // {id, text} leave the server, never is_correct /
                        // error_text — the preview must see exactly what a
                        // student sees.
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
                'id' => $course->id,
                'slug' => $course->slug,
                'title' => $course->title,
            ],
            'answers' => [],
            'lessonStatus' => null,
            'feedback' => null,
            'practiceTasks' => $lesson->practiceTasks
                ->map(fn (PracticeTask $task): array => [
                    'id' => $task->id,
                    'statement' => $task->statement,
                    'expected_result_text' => $task->expected_result_text,
                    'order' => $task->order,
                    'is_published' => $task->is_published,
                    // Manual mapping is the practice-spoiler guard (the
                    // mirror of the options guard above): only
                    // {id, statement, expected_result_text, order} leave
                    // the server, never seed_sql / expected_hash /
                    // expected_rows.
                ])
                ->values()
                ->all(),
            'passedPracticeTaskIds' => [],
            'practiceFeedback' => null,
            'aiFeedback' => null,
            'extraTask' => null,
            // Зеркальные пороги минимума (LessonController::show), но от
            // ВСЕХ отрендеренных задач: preview показывает и черновики,
            // поэтому min(K, N) считается по той же коллекции, которую
            // админ видит списком.
            'requiredTheoryCount' => min(
                (int) config('progress.theory_required_per_lesson', 3),
                $lesson->theoryTasks->count(),
            ),
            'requiredPracticeCount' => min(
                (int) config('progress.practice_required_per_lesson', 1),
                $lesson->practiceTasks->count(),
            ),
            // Следующий урок для навигации preview: publishedOnly false —
            // preview-список включает черновики, ссылки строятся по id
            // (/admin/courses/{course}/preview/lessons/{lesson}).
            'nextLesson' => ($next = app(NextLessonResolver::class)($course, $lesson, publishedOnly: false)) !== null
                ? ['id' => $next->id, 'slug' => $next->slug, 'title' => $next->title]
                : null,
            'previewMode' => true,
        ]);
    }
}
