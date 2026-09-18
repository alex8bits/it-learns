<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\BlockUserController;
use App\Http\Controllers\Admin\CheckPracticeTaskController;
use App\Http\Controllers\Admin\CourseController;
use App\Http\Controllers\Admin\CoursePreviewController;
use App\Http\Controllers\Admin\CoursePromptController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\GlobalPromptController;
use App\Http\Controllers\Admin\LessonController;
use App\Http\Controllers\Admin\LevelController;
use App\Http\Controllers\Admin\PaymentController;
use App\Http\Controllers\Admin\PracticeTaskController;
use App\Http\Controllers\Admin\PromptHistoryController;
use App\Http\Controllers\Admin\PromptPlaygroundController;
use App\Http\Controllers\Admin\TheoryTaskController;
use App\Http\Controllers\Admin\UnblockUserController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\UserLlmLimitController;
use Illuminate\Support\Facades\Route;

/*
 * All routes here are mounted under `/admin` and named `admin.*`
 * by the surrounding Route::group in bootstrap/app.php. Do NOT add
 * a second `prefix('admin')` / `name('admin.')` here — it would
 * produce double prefixes.
 */

// Dashboard
Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

// Users
Route::prefix('users')->name('users.')->group(function (): void {
    Route::get('/', [UserController::class, 'index'])->name('index');
    Route::get('/{user}', [UserController::class, 'show'])->name('show');
    Route::get('/{user}/edit', [UserController::class, 'edit'])->name('edit');
    Route::patch('/{user}', [UserController::class, 'update'])->name('update');
    Route::post('/{user}/block', BlockUserController::class)->name('block');
    Route::post('/{user}/unblock', UnblockUserController::class)->name('unblock');
    Route::get('/{user}/llm-limit', [UserLlmLimitController::class, 'edit'])->name('llm-limit.edit');
    Route::patch('/{user}/llm-limit', [UserLlmLimitController::class, 'update'])->name('llm-limit.update');
});

// Payments (read-only admin views + refund — the single payment mutation,
// Stage 9; every refund is audit-logged by the RefundPayment action)
Route::prefix('payments')->name('payments.')->group(function (): void {
    Route::get('/', [PaymentController::class, 'index'])->name('index');
    Route::get('/{payment}', [PaymentController::class, 'show'])
        ->whereNumber('payment')
        ->name('show');
    Route::post('{payment}/refund', [PaymentController::class, 'refund'])
        ->whereNumber('payment')
        ->name('refund');
});

// Courses (Stage 6: full CRUD + course prompt page + nested levels)
Route::prefix('courses')->name('courses.')->group(function (): void {
    Route::get('/', [CourseController::class, 'index'])->name('index');
    Route::get('/create', [CourseController::class, 'create'])->name('create');
    Route::post('/', [CourseController::class, 'store'])->name('store');
    Route::get('/{course}', [CourseController::class, 'show'])->whereNumber('course')->name('show');
    Route::get('/{course}/edit', [CourseController::class, 'edit'])->whereNumber('course')->name('edit');
    Route::patch('/{course}', [CourseController::class, 'update'])->whereNumber('course')->name('update');
    Route::delete('/{course}', [CourseController::class, 'destroy'])->whereNumber('course')->name('destroy');
    Route::get('/{course}/prompt', [CoursePromptController::class, 'edit'])->whereNumber('course')->name('prompt.edit');
    Route::patch('/{course}/prompt', [CoursePromptController::class, 'update'])->whereNumber('course')->name('prompt.update');
    // Read-only course preview «as a student sees it» (Stage 11): renders
    // the user-facing Vue pages without the `published` gates. GET-only —
    // no progress/AI POST exists here, so nothing can ever be written.
    Route::get('/{course}/preview', [CoursePreviewController::class, 'show'])->whereNumber('course')->name('preview.show');
    Route::get('/{course}/preview/lessons/{lesson}', [CoursePreviewController::class, 'lesson'])
        ->whereNumber('course')
        ->whereNumber('lesson')
        ->name('preview.lesson');
    Route::post('/{course}/levels', [LevelController::class, 'store'])->whereNumber('course')->name('levels.store');
});

// Levels (nested under courses; managed from the course card)
Route::prefix('levels')->name('levels.')->group(function (): void {
    Route::patch('/{level}', [LevelController::class, 'update'])->whereNumber('level')->name('update');
    Route::delete('/{level}', [LevelController::class, 'destroy'])->whereNumber('level')->name('destroy');
    Route::get('/{level}/lessons/create', [LessonController::class, 'create'])->whereNumber('level')->name('lessons.create');
    Route::post('/{level}/lessons', [LessonController::class, 'store'])->whereNumber('level')->name('lessons.store');
});

// Lessons (nested under levels; lesson creation lives under levels,
// theory-task and practice-task creation live under the lesson)
Route::prefix('lessons')->name('lessons.')->group(function (): void {
    Route::get('/{lesson}/edit', [LessonController::class, 'edit'])->whereNumber('lesson')->name('edit');
    Route::patch('/{lesson}', [LessonController::class, 'update'])->whereNumber('lesson')->name('update');
    Route::delete('/{lesson}', [LessonController::class, 'destroy'])->whereNumber('lesson')->name('destroy');
    Route::get('/{lesson}/theory-tasks/create', [TheoryTaskController::class, 'create'])
        ->whereNumber('lesson')
        ->name('theory-tasks.create');
    Route::post('/{lesson}/theory-tasks', [TheoryTaskController::class, 'store'])
        ->whereNumber('lesson')
        ->name('theory-tasks.store');
    Route::get('/{lesson}/practice-tasks/create', [PracticeTaskController::class, 'create'])
        ->whereNumber('lesson')
        ->name('practice-tasks.create');
    Route::post('/{lesson}/practice-tasks', [PracticeTaskController::class, 'store'])
        ->whereNumber('lesson')
        ->name('practice-tasks.store');
});

// Theory tasks (nested under lessons; edit/update/destroy by the task id —
// same shape as levels/lessons, managed from the lesson edit page)
Route::prefix('theory-tasks')->name('theory-tasks.')->group(function (): void {
    Route::get('/{task}/edit', [TheoryTaskController::class, 'edit'])->whereNumber('task')->name('edit');
    Route::patch('/{task}', [TheoryTaskController::class, 'update'])->whereNumber('task')->name('update');
    Route::delete('/{task}', [TheoryTaskController::class, 'destroy'])->whereNumber('task')->name('destroy');
});

// Practice tasks (edit/update/destroy by the task id — mirrors the
// theory-tasks block, managed from the lesson edit page; `check` runs the
// reference query of the Create/Edit form in an isolated environment)
Route::prefix('practice-tasks')->name('practice-tasks.')->group(function (): void {
    Route::post('/check', CheckPracticeTaskController::class)
        ->middleware('throttle:practice-check')
        ->name('check');
    Route::get('/{task}/edit', [PracticeTaskController::class, 'edit'])->whereNumber('task')->name('edit');
    Route::patch('/{task}', [PracticeTaskController::class, 'update'])->whereNumber('task')->name('update');
    Route::delete('/{task}', [PracticeTaskController::class, 'destroy'])->whereNumber('task')->name('destroy');
});

// Prompts (Stage 4: global system prompt editor + version history)
Route::prefix('prompts')->name('prompts.')->group(function (): void {
    Route::get('/', [GlobalPromptController::class, 'edit'])->name('index');
    Route::patch('/global', [GlobalPromptController::class, 'update'])->name('global.update');
    Route::get('/history', [PromptHistoryController::class, 'index'])->name('history.index');
    Route::post('/history/{version}/rollback', [PromptHistoryController::class, 'rollback'])
        ->whereNumber('version')
        ->name('history.rollback');
    // Playground (Stage 11): live LlmClient test call with the global +
    // course prompt glue. Throttled — every run spends provider tokens
    // from the daily budget, same as the premium AI routes.
    Route::get('/playground', [PromptPlaygroundController::class, 'edit'])->name('playground');
    Route::post('/playground/run', [PromptPlaygroundController::class, 'run'])
        ->middleware('throttle:ai-playground')
        ->name('playground.run');
});

// Audit Logs
Route::prefix('audit-logs')->name('audit-logs.')->group(function (): void {
    Route::get('/', [AuditLogController::class, 'index'])->name('index');
    Route::get('/{log}', [AuditLogController::class, 'show'])->name('show');
});
