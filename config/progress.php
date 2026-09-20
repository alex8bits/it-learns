<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Lesson Minimum Requirements
    |--------------------------------------------------------------------------
    |
    | The "minimum" that completes a lesson (LessonCompletionChecker):
    | the first N published theory tasks by order must be answered
    | correctly AND the first M published practice tasks by order must
    | have a Passed submission. Tasks beyond the window are optional
    | (they never block completion). When a lesson has fewer tasks than
    | the threshold, all of them are required (min(threshold, N)).
    | A threshold of 0 or less makes the part vacuously done.
    |
    */

    'theory_required_per_lesson' => (int) env('PROGRESS_THEORY_REQUIRED_PER_LESSON', 3),

    'practice_required_per_lesson' => (int) env('PROGRESS_PRACTICE_REQUIRED_PER_LESSON', 1),

];
