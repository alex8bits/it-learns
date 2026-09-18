<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\PracticeEnvironmentStatus;
use App\Enums\PracticeRuntime;
use App\Models\PracticeEnvironment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PracticeEnvironmentTest extends TestCase
{
    public function test_casts_map_enums_meta_and_datetimes(): void
    {
        $casts = (new PracticeEnvironment)->getCasts();

        $this->assertSame(PracticeRuntime::class, $casts['runtime']);
        $this->assertSame(PracticeEnvironmentStatus::class, $casts['status']);
        $this->assertSame('array', $casts['connection_meta']);
        $this->assertSame('datetime', $casts['started_at']);
        $this->assertSame('datetime', $casts['destroyed_at']);
    }

    public function test_fillable_contains_exactly_the_declared_fields(): void
    {
        $this->assertSame(
            ['user_id', 'practice_task_id', 'runtime', 'status', 'connection_meta', 'started_at', 'error'],
            (new PracticeEnvironment)->getFillable(),
        );
    }

    public function test_factory_creates_ready_environment_for_user(): void
    {
        $user = User::factory()->create();
        $environment = PracticeEnvironment::factory()->create(['user_id' => $user->id]);

        $this->assertDatabaseHas('practice_environments', [
            'id' => $environment->id,
            'user_id' => $user->id,
            'practice_task_id' => null,
            'runtime' => PracticeRuntime::Sqlite->value,
            'status' => PracticeEnvironmentStatus::Ready->value,
        ]);

        $this->assertTrue($environment->user->is($user));
        $this->assertSame(PracticeRuntime::Sqlite, $environment->runtime);
        $this->assertInstanceOf(PracticeEnvironmentStatus::class, $environment->status);
        $this->assertSame(PracticeEnvironmentStatus::Ready, $environment->status);
    }

    public function test_connection_meta_round_trips_as_array_with_dates_cast(): void
    {
        $meta = ['path' => storage_path('framework/practice/probe.sqlite')];
        $environment = PracticeEnvironment::factory()->create(['connection_meta' => $meta]);

        $this->assertSame($meta, $environment->connection_meta);

        $fresh = $environment->fresh();

        $this->assertSame($meta, $fresh->connection_meta);
        $this->assertInstanceOf(Carbon::class, $fresh->started_at);
        $this->assertNull($fresh->destroyed_at);
        $this->assertNull($fresh->error);
    }

    public function test_factory_definition_uses_unique_sqlite_file_path(): void
    {
        $meta = PracticeEnvironment::factory()->make()->connection_meta;

        $this->assertIsArray($meta);

        $path = $meta['path'];

        $this->assertIsString($path);
        $this->assertStringEndsWith('.sqlite', $path);

        $another = PracticeEnvironment::factory()->make()->connection_meta;

        $this->assertIsArray($another);
        $this->assertNotSame($path, $another['path']);
    }

    public function test_failed_state_sets_status_and_error(): void
    {
        $environment = PracticeEnvironment::factory()->failed()->create();

        $this->assertSame(PracticeEnvironmentStatus::Failed, $environment->status);
        $this->assertNotNull($environment->error);
        $this->assertDatabaseHas('practice_environments', [
            'id' => $environment->id,
            'status' => PracticeEnvironmentStatus::Failed->value,
        ]);
    }

    public function test_destroyed_state_sets_status_and_destroyed_at(): void
    {
        $environment = PracticeEnvironment::factory()->destroyed()->create();

        $this->assertSame(PracticeEnvironmentStatus::Destroyed, $environment->status);
        $this->assertNotNull($environment->destroyed_at);
        $this->assertDatabaseHas('practice_environments', [
            'id' => $environment->id,
            'status' => PracticeEnvironmentStatus::Destroyed->value,
        ]);
    }
}
