<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\BlockUserController;
use App\Http\Controllers\Admin\CourseController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\PaymentController;
use App\Http\Controllers\Admin\PromptController;
use App\Http\Controllers\Admin\UnblockUserController;
use App\Http\Controllers\Admin\UserController;
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
});

// Payments (read-only admin views; manual operations land in Stage 3.1)
Route::prefix('payments')->name('payments.')->group(function (): void {
    Route::get('/', [PaymentController::class, 'index'])->name('index');
    Route::get('/{payment}', [PaymentController::class, 'show'])
        ->whereNumber('payment')
        ->name('show');
});

// Courses (Stage 5+ stub — no model yet)
Route::prefix('courses')->name('courses.')->group(function (): void {
    Route::get('/', [CourseController::class, 'index'])->name('index');
    Route::get('/{course}', [CourseController::class, 'show'])
        ->whereNumber('course')
        ->name('show');
});

// Prompts (Stage 4 stub — no model yet)
Route::prefix('prompts')->name('prompts.')->group(function (): void {
    Route::get('/', [PromptController::class, 'index'])->name('index');
});

// Audit Logs
Route::prefix('audit-logs')->name('audit-logs.')->group(function (): void {
    Route::get('/', [AuditLogController::class, 'index'])->name('index');
    Route::get('/{log}', [AuditLogController::class, 'show'])->name('show');
});
