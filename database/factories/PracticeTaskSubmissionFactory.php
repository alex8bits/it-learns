<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PracticeAttemptStatus;
use App\Models\PracticeTask;
use App\Models\PracticeTaskSubmission;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PracticeTaskSubmission>
 */
class PracticeTaskSubmissionFactory extends Factory
{
    /**
     * Define the model's default state: a failed attempt (the default
     * outcome a student needs feedback for). Attempts are an unlimited
     * append-only history, so the factory never has to reconcile with
     * existing rows.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'practice_task_id' => PracticeTask::factory(),
            'code' => 'SELECT title FROM books',
            'status' => PracticeAttemptStatus::Failed,
            'created_at' => now(),
        ];
    }

    /**
     * Passed attempt: the task is solved.
     */
    public function passed(): static
    {
        return $this->state(fn (): array => [
            'status' => PracticeAttemptStatus::Passed,
        ]);
    }

    /**
     * Failed attempt (the default), kept for explicitness next to
     * `passed()`/`error()`.
     */
    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => PracticeAttemptStatus::Failed,
        ]);
    }

    /**
     * Attempt that failed to execute: the reason lives in `error_text`.
     */
    public function error(): static
    {
        return $this->state(fn (): array => [
            'status' => PracticeAttemptStatus::Error,
            'error_text' => 'SQLite: no such table: books',
        ]);
    }
}
