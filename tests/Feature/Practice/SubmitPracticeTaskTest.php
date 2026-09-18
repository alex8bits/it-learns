<?php

declare(strict_types=1);

namespace Tests\Feature\Practice;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\Level;
use App\Models\PracticeTask;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

class SubmitPracticeTaskTest extends TestCase
{
    private string $storagePath;

    protected function setUp(): void
    {
        parent::setUp();

        // Изоляция файлов практики в уникальном temp-каталоге
        // (паттерн LocalSqlitePracticeEnvironmentTest) — никогда в
        // реальный storage/framework/practice.
        $this->storagePath = sys_get_temp_dir().'/practice-feature-'.Str::uuid()->toString();
        config(['practice.storage_path' => $this->storagePath]);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->storagePath);

        parent::tearDown();
    }

    public function test_submit_runs_the_real_environment_and_records_the_attempt(): void
    {
        $user = User::factory()->create();
        $lesson = $this->createPublishedLesson();
        $task = PracticeTask::factory()->for($lesson)->withSeedScript()->create();

        $response = $this->actingAs($user)
            ->from(route('lessons.show', $lesson->slug))
            ->post(route('practice-tasks.submit', $task), [
                'code' => 'SELECT id, title, year FROM books',
            ]);

        $response->assertRedirect(route('lessons.show', $lesson->slug));
        $response->assertSessionHas('practice_feedback');

        $this->assertDatabaseHas('practice_task_submissions', [
            'user_id' => $user->id,
            'practice_task_id' => $task->id,
            'code' => 'SELECT id, title, year FROM books',
            'status' => 'passed',
        ]);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $lesson = $this->createPublishedLesson();
        $task = PracticeTask::factory()->for($lesson)->withSeedScript()->create();

        $response = $this->post(route('practice-tasks.submit', $task), [
            'code' => 'SELECT 1',
        ]);

        $response->assertRedirect(route('login'));
        $this->assertDatabaseCount('practice_task_submissions', 0);
    }

    public function test_missing_task_returns_404(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->post(route('practice-tasks.submit', 999999), ['code' => 'SELECT 1']);

        $response->assertNotFound();
        $this->assertDatabaseCount('practice_task_submissions', 0);
    }

    public function test_unpublished_task_returns_404_without_writing(): void
    {
        $user = User::factory()->create();
        $lesson = $this->createPublishedLesson();
        $task = PracticeTask::factory()->for($lesson)->unpublished()->withSeedScript()->create();

        $response = $this->actingAs($user)
            ->post(route('practice-tasks.submit', $task), ['code' => 'SELECT id FROM books']);

        $response->assertNotFound();
        $this->assertDatabaseCount('practice_task_submissions', 0);
    }

    /**
     * A published course -> level -> lesson chain the practice task
     * belongs to (the submission flow guards the whole triple).
     */
    private function createPublishedLesson(): Lesson
    {
        $course = Course::factory()->published()->create();
        $level = Level::factory()->for($course)->create();

        return Lesson::factory()->for($level)->create();
    }

    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir.'/'.$entry;

            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
