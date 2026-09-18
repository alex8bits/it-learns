<?php

declare(strict_types=1);

namespace App\Services\Practice;

use App\Enums\PracticeEnvironmentStatus;
use App\Enums\PracticeRuntime;
use App\Models\PracticeEnvironment;
use App\Services\Practice\Concerns\LogsPracticeEnvironmentEvents;
use App\Services\Practice\Docker\DockerClient;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Throwable;

/**
 * The TTL safety net behind `practice:prune-environments` (every five
 * minutes): a PHP process crashing between provision and the destroy
 * finally leaves a labeled container running and an unfinished
 * practice_environments row behind. The pruner force-removes labeled
 * containers older than practice.docker.prune.ttl_minutes, flips the
 * dangling unfinished rows to Destroyed and unlinks the forgotten
 * per-attempt SQLite files. The destroy-in-finally of the practice
 * action stays the primary cleanup path — this second line of defence
 * is idempotent and safe to re-run on an already clean state.
 */
final class PracticeEnvironmentPruner
{
    use LogsPracticeEnvironmentEvents;

    private const REASON_TTL_EXCEEDED = 'ttl exceeded';

    private const ERROR_PRUNED = 'pruned: ttl exceeded';

    public function __construct(private readonly DockerClient $docker) {}

    /**
     * Run both sweeps and return their counters. `$now` is injectable
     * for deterministic tests (the SubscriptionService::expireDue
     * pattern).
     *
     * @return array{containers: int, environments: int}
     */
    public function prune(?CarbonInterface $now = null): array
    {
        $now ??= now();

        /** @var int $ttlMinutes */
        $ttlMinutes = config('practice.docker.prune.ttl_minutes');

        $threshold = $now->copy()->subMinutes($ttlMinutes);

        return [
            'containers' => $this->pruneContainers($threshold),
            'environments' => $this->pruneEnvironments($now, $threshold),
        ];
    }

    /**
     * Force-remove labeled practice containers created before the TTL
     * threshold. A missing or unparseable created_at collapses to
     * "stale" (fail-safe): dropping one extra ephemeral container is
     * cheaper than leaving a hanging one behind.
     */
    private function pruneContainers(CarbonInterface $threshold): int
    {
        $pruned = 0;

        foreach ($this->docker->listLabeled() as $container) {
            if (! $this->containerIsStale($container['created_at'] ?? null, $threshold)) {
                continue;
            }

            $this->docker->removeContainer($container['container_id']);

            $this->logPruned(self::REASON_TTL_EXCEEDED, null, null, $container['container_id']);

            $pruned++;
        }

        return $pruned;
    }

    /**
     * Flip unfinished rows whose started_at predates the TTL threshold
     * to Destroyed and unlink their forgotten SQLite files. Terminal
     * Failed/Destroyed rows and fresh rows are never revisited.
     * Single-table write — no DB::transaction (rule №6 covers 2+
     * table writes, the SubscriptionService::expireDue precedent).
     */
    private function pruneEnvironments(CarbonInterface $now, CarbonInterface $threshold): int
    {
        $environments = PracticeEnvironment::query()
            ->whereIn('status', [
                PracticeEnvironmentStatus::Provisioning->value,
                PracticeEnvironmentStatus::Ready->value,
                PracticeEnvironmentStatus::Running->value,
            ])
            ->where('started_at', '<', $threshold)
            ->get();

        foreach ($environments as $environment) {
            $this->removeForgottenSqliteFile($environment);

            // Direct attribute write (the manager destroy() style):
            // destroyed_at is deliberately not mass-assignable — the
            // pruner joins the manager as one of its writers.
            $environment->status = PracticeEnvironmentStatus::Destroyed->value;
            $environment->destroyed_at = $now->toDateTimeString();
            $environment->error = self::ERROR_PRUNED;
            $environment->save();

            /** @var PracticeRuntime|null $runtime */
            $runtime = $environment->runtime;

            $this->logPruned(self::REASON_TTL_EXCEEDED, $runtime, $environment->id);
        }

        return $environments->count();
    }

    /**
     * A container counts as stale when its creation time parses older
     * than the threshold. Unparseable or missing timestamps are stale
     * by definition — the fail-safe direction of the pruner.
     */
    private function containerIsStale(?string $createdAt, CarbonInterface $threshold): bool
    {
        if ($createdAt === null || trim($createdAt) === '') {
            return true;
        }

        try {
            return Carbon::parse($createdAt)->lessThan($threshold);
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * Best-effort unlink of a forgotten per-attempt SQLite file (a
     * crashed process never reached its destroy finally). Docker rows
     * carry container metadata instead of a path — nothing to unlink.
     */
    private function removeForgottenSqliteFile(PracticeEnvironment $environment): void
    {
        /** @var mixed $path */
        $path = ($environment->connection_meta ?? [])['path'] ?? null;

        if (is_string($path) && $path !== '' && is_file($path)) {
            @unlink($path);
        }
    }
}
