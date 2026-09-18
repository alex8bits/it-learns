<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Practice;

use App\Services\Practice\PracticeConcurrencyLimiter;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The global semaphore over practice environments (Stage 10): slot
 * keys practice-slot:{i}, saturation -> null, disabled limit -> a
 * marker that is free to release. The array cache store of the test
 * env implements real lock semantics (owner-checked acquisition and
 * release), so the races below exercise the production logic.
 */
class PracticeConcurrencyLimiterTest extends TestCase
{
    /**
     * Every value that must read as "no global limit": the default
     * null plus the sub-one numbers a direct config() call can set.
     *
     * @return array<string, array{null|int}>
     */
    public static function disabledLimitProvider(): array
    {
        return [
            'null (default)' => [null],
            'zero' => [0],
            'negative' => [-3],
        ];
    }

    #[DataProvider('disabledLimitProvider')]
    public function test_disabled_limit_acquires_without_bound_and_releases_as_no_op(?int $limit): void
    {
        config(['practice.concurrency.max_environments' => $limit]);

        $limiter = new PracticeConcurrencyLimiter;

        $first = $limiter->acquire();
        $second = $limiter->acquire();

        $this->assertNotNull($first);
        $this->assertNotNull($second);

        $first->release();
        $first->release();
        $second->release();
    }

    public function test_single_slot_saturates_until_released(): void
    {
        config(['practice.concurrency.max_environments' => 1]);

        $limiter = new PracticeConcurrencyLimiter;

        $slot = $limiter->acquire();
        $this->assertNotNull($slot);

        $this->assertNull($limiter->acquire());

        $slot->release();

        $this->assertNotNull($limiter->acquire());
    }

    public function test_two_slots_allow_two_concurrent_acquisitions_only(): void
    {
        config(['practice.concurrency.max_environments' => 2]);

        $limiter = new PracticeConcurrencyLimiter;

        $first = $limiter->acquire();
        $second = $limiter->acquire();

        $this->assertNotNull($first);
        $this->assertNotNull($second);

        $this->assertNull($limiter->acquire());

        $first->release();

        $this->assertNotNull($limiter->acquire());
    }

    /**
     * The documented slot key is the coordination point with the rest
     * of the platform: an externally held practice-slot:{i} lock must
     * saturate the limiter exactly like an in-process acquisition.
     */
    public function test_externally_held_slot_key_saturates_the_limiter(): void
    {
        config(['practice.concurrency.max_environments' => 1]);

        $guard = Cache::lock('practice-slot:1', 10);
        $this->assertTrue($guard->get());

        $this->assertNull((new PracticeConcurrencyLimiter)->acquire());

        $guard->release();

        $this->assertNotNull((new PracticeConcurrencyLimiter)->acquire());
    }

    public function test_release_is_safe_to_call_repeatedly(): void
    {
        config(['practice.concurrency.max_environments' => 1]);

        $limiter = new PracticeConcurrencyLimiter;

        $slot = $limiter->acquire();
        $this->assertNotNull($slot);

        $slot->release();
        $slot->release();

        $this->assertNotNull($limiter->acquire());
    }
}
