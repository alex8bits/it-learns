<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\TheoryTask;
use App\Models\TheoryTaskOption;
use App\Models\User;
use App\Models\UserTheoryTaskAnswer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserTheoryTaskAnswer>
 */
class UserTheoryTaskAnswerFactory extends Factory
{
    /**
     * Define the model's default state: a correct answer. The default
     * `theory_task_id` is a task with options (`withOptions`), and the
     * picked option belongs to that same task — the closure receives the
     * already-expanded `theory_task_id`, so both explicit attributes and
     * `for($task)` (which replaces the FK before expansion) keep the
     * (task, option) pair consistent. When re-using an existing task via
     * `for()`, that task must have a correct option.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'theory_task_id' => TheoryTask::factory()->withOptions(),
            'option_id' => fn (array $attributes): ?int => TheoryTaskOption::query()
                ->where('theory_task_id', $attributes['theory_task_id'])
                ->where('is_correct', true)
                ->value('id'),
            'is_correct' => true,
            'answered_at' => now(),
        ];
    }
}
