<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Practice;

use App\Enums\PracticeEnvironmentStatus;
use App\Enums\PracticeRuntime;
use App\Models\PracticeEnvironment;
use App\Services\Practice\Docker\DockerClient;
use App\Services\Practice\PracticeEnvironmentPruner;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Unit coverage of the TTL safety net with the DockerClient mocked
 * (the DockerPracticeEnvironmentTest binding pattern): container age
 * classification (stale / fresh / unparseable fail-safe / boundary),
 * the DB sweep of unfinished rows vs terminal ones, the forgotten
 * SQLite file unlink, per-prune structured logging and idempotency.
 * No Docker daemon is needed.
 */
class PracticeEnvironmentPrunerTest extends TestCase
{
    private CarbonInterface $now;

    private MockInterface $docker;

    private PracticeEnvironmentPruner $pruner;

    protected function setUp(): void
    {
        parent::setUp();

        config(['practice.docker.prune.ttl_minutes' => 30]);

        // Frozen anchor: the TTL threshold is 2026-09-17 11:30:00.
        $this->now = Carbon::parse('2026-09-17 12:00:00');

        $this->docker = Mockery::mock(DockerClient::class);
        $this->instance(DockerClient::class, $this->docker);

        $this->pruner = app(PracticeEnvironmentPruner::class);
    }

    public function test_removes_stale_labeled_containers_and_keeps_fresh_ones(): void
    {
        $this->docker->shouldReceive('listLabeled')->once()->andReturn([
            ['container_id' => 'cid-stale', 'created_at' => '2026-09-17 10:00:00 +00:00 UTC'],
            ['container_id' => 'cid-fresh', 'created_at' => '2026-09-17 11:55:00 +00:00 UTC'],
        ]);
        $this->docker->shouldReceive('removeContainer')->once()->with('cid-stale');

        $result = $this->pruner->prune($this->now);

        $this->assertSame(['containers' => 1, 'environments' => 0], $result);
    }

    public function test_keeps_a_container_created_exactly_at_the_threshold(): void
    {
        $this->docker->shouldReceive('listLabeled')->once()->andReturn([
            ['container_id' => 'cid-boundary', 'created_at' => '2026-09-17 11:30:00 +00:00 UTC'],
        ]);
        $this->docker->shouldReceive('removeContainer')->never();

        $result = $this->pruner->prune($this->now);

        $this->assertSame(0, $result['containers']);
    }

    /**
     * @return array<string, array{0: string|null}>
     */
    public static function invalidCreatedAtProvider(): array
    {
        return [
            'missing timestamp' => [null],
            'empty timestamp' => [''],
            'whitespace timestamp' => ['   '],
            'unparseable timestamp' => ['not-a-timestamp'],
        ];
    }

    #[DataProvider('invalidCreatedAtProvider')]
    public function test_treats_invalid_container_timestamps_as_stale_fail_safe(?string $createdAt): void
    {
        $this->docker->shouldReceive('listLabeled')->once()->andReturn([
            ['container_id' => 'cid-unknown-age', 'created_at' => $createdAt],
        ]);
        $this->docker->shouldReceive('removeContainer')->once()->with('cid-unknown-age');

        $result = $this->pruner->prune($this->now);

        $this->assertSame(1, $result['containers']);
    }

    /**
     * @return array<string, array{0: PracticeEnvironmentStatus}>
     */
    public static function unfinishedEnvironmentProvider(): array
    {
        return [
            'provisioning' => [PracticeEnvironmentStatus::Provisioning],
            'ready' => [PracticeEnvironmentStatus::Ready],
            'running' => [PracticeEnvironmentStatus::Running],
        ];
    }

    #[DataProvider('unfinishedEnvironmentProvider')]
    public function test_marks_stale_unfinished_rows_destroyed(PracticeEnvironmentStatus $status): void
    {
        $this->docker->shouldReceive('listLabeled')->once()->andReturn([]);

        $stale = PracticeEnvironment::factory()->create([
            'status' => $status,
            'started_at' => $this->now->copy()->subMinutes(31),
        ]);

        // Fixture guard: the row really starts in the swept status.
        $this->assertSame($status, $stale->status);

        $result = $this->pruner->prune($this->now);

        $this->assertSame(1, $result['environments']);

        $stale->refresh();

        $this->assertSame(PracticeEnvironmentStatus::Destroyed, $stale->status);
        $this->assertSame('2026-09-17 12:00:00', (string) $stale->destroyed_at);
        $this->assertSame('pruned: ttl exceeded', $stale->error);
    }

    public function test_leaves_fresh_and_terminal_rows_alone(): void
    {
        $this->docker->shouldReceive('listLabeled')->once()->andReturn([]);

        $fresh = PracticeEnvironment::factory()->create([
            'started_at' => $this->now->copy()->subMinutes(10),
        ]);
        $destroyed = PracticeEnvironment::factory()->destroyed()->create([
            'started_at' => $this->now->copy()->subHours(2),
        ]);
        $failed = PracticeEnvironment::factory()->failed()->create([
            'started_at' => $this->now->copy()->subHours(2),
        ]);

        $result = $this->pruner->prune($this->now);

        $this->assertSame(['containers' => 0, 'environments' => 0], $result);

        $this->assertDatabaseHas('practice_environments', [
            'id' => $fresh->id,
            'status' => PracticeEnvironmentStatus::Ready->value,
            'destroyed_at' => null,
            'error' => null,
        ]);
        $this->assertDatabaseHas('practice_environments', [
            'id' => $destroyed->id,
            'status' => PracticeEnvironmentStatus::Destroyed->value,
            'error' => null,
        ]);
        $this->assertDatabaseHas('practice_environments', [
            'id' => $failed->id,
            'status' => PracticeEnvironmentStatus::Failed->value,
            'error' => 'SQLite: unable to create the practice database file',
        ]);
    }

    public function test_unlinks_the_forgotten_sqlite_file_of_a_pruned_row(): void
    {
        $this->docker->shouldReceive('listLabeled')->once()->andReturn([]);

        $path = (string) tempnam(sys_get_temp_dir(), 'pruner-test');
        $this->assertFileExists($path);

        try {
            PracticeEnvironment::factory()->create([
                'connection_meta' => ['path' => $path],
                'started_at' => $this->now->copy()->subMinutes(31),
            ]);

            $result = $this->pruner->prune($this->now);

            $this->assertSame(1, $result['environments']);
            $this->assertFileDoesNotExist($path);
        } finally {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    public function test_prunes_docker_rows_that_carry_container_meta_instead_of_a_path(): void
    {
        $this->docker->shouldReceive('listLabeled')->once()->andReturn([]);

        $dockerRow = PracticeEnvironment::factory()->running()->create([
            'runtime' => PracticeRuntime::Mysql,
            'connection_meta' => ['container_id' => 'cid-db', 'image' => 'mysql:8'],
            'started_at' => $this->now->copy()->subMinutes(45),
        ]);

        $result = $this->pruner->prune($this->now);

        $this->assertSame(1, $result['environments']);

        $dockerRow->refresh();

        $this->assertSame(PracticeEnvironmentStatus::Destroyed, $dockerRow->status);
        $this->assertSame('pruned: ttl exceeded', $dockerRow->error);
    }

    public function test_returns_zero_counters_on_a_clean_state(): void
    {
        $this->docker->shouldReceive('listLabeled')->once()->andReturn([]);

        $this->assertSame(['containers' => 0, 'environments' => 0], $this->pruner->prune($this->now));
    }

    public function test_rerun_after_a_prune_finds_nothing(): void
    {
        $this->docker->shouldReceive('listLabeled')->twice()->andReturn([]);

        PracticeEnvironment::factory()->running()->create([
            'started_at' => $this->now->copy()->subMinutes(45),
        ]);

        $this->assertSame(1, $this->pruner->prune($this->now)['environments']);
        $this->assertSame(['containers' => 0, 'environments' => 0], $this->pruner->prune($this->now));
    }

    public function test_counts_both_sweeps_together(): void
    {
        $this->docker->shouldReceive('listLabeled')->once()->andReturn([
            ['container_id' => 'cid-a', 'created_at' => '2026-09-17 09:00:00 +00:00 UTC'],
            ['container_id' => 'cid-b', 'created_at' => '2026-09-17 10:00:00 +00:00 UTC'],
        ]);
        $this->docker->shouldReceive('removeContainer')->twice();

        PracticeEnvironment::factory()->provisioning()->create([
            'started_at' => $this->now->copy()->subHour(),
        ]);
        PracticeEnvironment::factory()->running()->create([
            'started_at' => $this->now->copy()->subHour(),
        ]);

        $result = $this->pruner->prune($this->now);

        $this->assertSame(['containers' => 2, 'environments' => 2], $result);
    }

    public function test_logs_each_prune_with_metadata_only(): void
    {
        $environmentId = PracticeEnvironment::factory()->create([
            'started_at' => $this->now->copy()->subMinutes(31),
        ])->id;

        $this->docker->shouldReceive('listLabeled')->once()->andReturn([
            ['container_id' => 'cid-stale', 'created_at' => '2026-09-17 10:00:00 +00:00 UTC'],
        ]);
        $this->docker->shouldReceive('removeContainer')->once()->with('cid-stale');

        Log::shouldReceive('info')->once()->with('practice.environment_pruned', Mockery::on(function (array $context): bool {
            return $context['runtime'] === null
                && $context['environment_id'] === null
                && $context['container_id'] === 'cid-stale'
                && $context['reason'] === 'ttl exceeded';
        }));
        Log::shouldReceive('info')->once()->with('practice.environment_pruned', Mockery::on(function (array $context) use ($environmentId): bool {
            return $context['runtime'] === 'sqlite'
                && $context['environment_id'] === $environmentId
                && $context['container_id'] === null
                && $context['reason'] === 'ttl exceeded';
        }));

        $result = $this->pruner->prune($this->now);

        $this->assertSame(['containers' => 1, 'environments' => 1], $result);
    }
}
