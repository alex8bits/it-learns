<?php

declare(strict_types=1);

namespace App\Services\Practice;

use Illuminate\Support\Facades\Cache;

/**
 * Global semaphore over concurrently running practice environments —
 * the second anti-DoS layer of the attempt cycle (Stage 10 design),
 * after the per-user lock in RunPracticeTaskAction. At most
 * practice.concurrency.max_environments attempts across all users may
 * each hold one `practice-slot:{i}` cache lock; the driver-agnostic
 * key makes the cap apply to local-sqlite and docker alike.
 *
 * The default (null) keeps the pre-Stage-10 behaviour — no global
 * limit: a default local-sqlite installation must not get an unwanted
 * global serialization, docker installations opt in via
 * PRACTICE_MAX_CONCURRENT_ENVIRONMENTS.
 *
 * The config and the TTL are read at call time (no constructor state),
 * so the limiter is stateless and safe as a shared service. Slot TTL
 * is practice.lock_ttl_seconds — the same self-expiring discipline as
 * the per-user environment lock, so a hard process crash cannot leak
 * a slot forever.
 */
final class PracticeConcurrencyLimiter
{
    /**
     * Occupy the first free global slot. Returns null when every slot
     * is taken (saturation — the caller answers Busy without touching
     * the environment manager), or a slot marker which is a no-op to
     * release while the concurrency limit is disabled.
     */
    public function acquire(): ?PracticeSlotLock
    {
        $max = config('practice.concurrency.max_environments');

        if (! is_numeric($max) || (int) $max < 1) {
            return PracticeSlotLock::unlimited();
        }

        $ttl = (int) config('practice.lock_ttl_seconds', 120);

        for ($slot = 1; $slot <= (int) $max; $slot++) {
            $lock = Cache::lock('practice-slot:'.$slot, $ttl);

            if ($lock->get()) {
                return PracticeSlotLock::acquired($lock);
            }
        }

        return null;
    }
}
