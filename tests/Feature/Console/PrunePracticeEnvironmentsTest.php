<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\PracticeEnvironmentStatus;
use App\Models\PracticeEnvironment;
use App\Services\Practice\Docker\DockerClient;
use Illuminate\Support\Carbon;
use Illuminate\Testing\PendingCommand;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Smoke test of the `practice:prune-environments` console command
 * (HTTP-less boundary). The sweep rules themselves are covered in Unit
 * by PracticeEnvironmentPrunerTest — here only the happy path. The
 * DockerClient is swapped for a mock: the real CliDockerClient would
 * shell out to `docker ps`, and the suite must not depend on a
 * running daemon.
 */
class PrunePracticeEnvironmentsTest extends TestCase
{
    private MockInterface $docker;

    protected function setUp(): void
    {
        parent::setUp();

        // Freeze time so the fixture dates vs the TTL threshold stay
        // deterministic (precedent: ExpireSubscriptionsTest).
        $this->travelTo(Carbon::parse('2026-09-17 12:00:00'));
        config(['practice.docker.prune.ttl_minutes' => 30]);

        $this->docker = Mockery::mock(DockerClient::class);
        $this->docker->shouldReceive('listLabeled')->andReturn([]);
        $this->instance(DockerClient::class, $this->docker);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_prunes_dangling_environments(): void
    {
        $stale = PracticeEnvironment::factory()->running()->create([
            'started_at' => now()->subMinutes(31),
        ]);

        // `artisan()` is stubbed as `PendingCommand|int` by larastan; with
        // the default mocked console output it is always a PendingCommand.
        /** @var PendingCommand $command */
        $command = $this->artisan('practice:prune-environments');

        $command->expectsOutputToContain('Pruned practice containers: 0, environments: 1')
            ->assertExitCode(0);

        // PendingCommand runs on destruct; drop it now so the command (and
        // its expectations) execute before the database assertions below.
        unset($command);

        $this->assertDatabaseHas('practice_environments', [
            'id' => $stale->id,
            'status' => PracticeEnvironmentStatus::Destroyed->value,
            'error' => 'pruned: ttl exceeded',
        ]);
    }
}
