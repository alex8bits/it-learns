<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PracticeEnvironmentStatus;
use App\Enums\PracticeRuntime;
use App\Models\PracticeEnvironment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PracticeEnvironment>
 */
class PracticeEnvironmentFactory extends Factory
{
    /**
     * Define the model's default state: a ready local SQLite environment
     * pointing at a unique per-attempt database file.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'practice_task_id' => null,
            'runtime' => PracticeRuntime::Sqlite,
            'status' => PracticeEnvironmentStatus::Ready,
            'connection_meta' => ['path' => storage_path('framework/practice/'.Str::uuid()->toString().'.sqlite')],
            'started_at' => now(),
            'destroyed_at' => null,
            'error' => null,
        ];
    }

    /**
     * Failed provisioning: the environment could not be brought up, the
     * reason is stored in `error`.
     */
    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => PracticeEnvironmentStatus::Failed,
            'started_at' => null,
            'destroyed_at' => null,
            'error' => 'SQLite: unable to create the practice database file',
        ]);
    }

    /**
     * Unfinished lifecycle stages the TTL pruner sweeps: the attempt
     * never reached a terminal state (a crashed process left the row
     * hanging). `ready` is the default definition state.
     */
    public function provisioning(): static
    {
        return $this->state(fn (): array => [
            'status' => PracticeEnvironmentStatus::Provisioning,
        ]);
    }

    /**
     * A dangling Running row (see provisioning()).
     */
    public function running(): static
    {
        return $this->state(fn (): array => [
            'status' => PracticeEnvironmentStatus::Running,
        ]);
    }

    /**
     * Destroyed environment: torn down after a completed attempt.
     */
    public function destroyed(): static
    {
        return $this->state(fn (): array => [
            'status' => PracticeEnvironmentStatus::Destroyed,
            'destroyed_at' => now(),
        ]);
    }
}
