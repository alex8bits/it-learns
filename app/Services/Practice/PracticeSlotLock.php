<?php

declare(strict_types=1);

namespace App\Services\Practice;

use Illuminate\Contracts\Cache\Lock;

/**
 * Marker for one acquired global practice slot, handed back by
 * PracticeConcurrencyLimiter::acquire(). It wraps the framework Lock
 * instance that won the race: Cache::lock()->get() returns only a
 * bool, and a later release from a fresh lock object would fail the
 * owner check, so the owning instance must be kept until the release.
 * The unlimited configuration yields a marker carrying no lock at all
 * — release() is a no-op there and safe to call repeatedly on every
 * marker (the guard nulls the lock out after the first release).
 */
final class PracticeSlotLock
{
    private ?Lock $lock;

    private function __construct(?Lock $lock)
    {
        $this->lock = $lock;
    }

    /**
     * Marker for a disabled concurrency limit: nothing was acquired,
     * so there is nothing to release.
     */
    public static function unlimited(): self
    {
        return new self(null);
    }

    /**
     * Marker for a slot whose lock acquisition succeeded.
     */
    public static function acquired(Lock $lock): self
    {
        return new self($lock);
    }

    /**
     * Give the slot back to the pool. No-op for the unlimited marker
     * and for an already released one.
     */
    public function release(): void
    {
        if ($this->lock !== null) {
            $this->lock->release();
            $this->lock = null;
        }
    }
}
