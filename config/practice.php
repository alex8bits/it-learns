<?php

declare(strict_types=1);

use App\Services\Practice\Docker\DockerPracticeEnvironment;
use App\Services\Practice\LocalSqlitePracticeEnvironment;

return [

    /*
    |--------------------------------------------------------------------------
    | Practice Environment Driver
    |--------------------------------------------------------------------------
    |
    | The isolated practice runtime, selected via PRACTICE_DRIVER
    | (.env). Must be a key of the `managers` whitelist below,
    | otherwise the application fails loudly on boot
    | (AppServiceProvider).
    |
    */

    'driver' => env('PRACTICE_DRIVER', 'local-sqlite'),

    /*
    |--------------------------------------------------------------------------
    | Concurrency & Lock TTL (Stage 10)
    |--------------------------------------------------------------------------
    |
    | lock_ttl_seconds covers the worst case of one attempt cycle —
    | the mysql:8 container boot (10-30 s) plus the execution timeout
    | plus a margin — and self-expires a lock leaked by a hard process
    | crash. concurrency.max_environments is the global semaphore over
    | the running practice environments (the practice-slot:{i} cache
    | locks of PracticeConcurrencyLimiter); null (the default) means
    | no global limit — the pre-Stage-10 behaviour, so a default
    | local-sqlite installation is not accidentally serialized. Docker
    | installations opt in via PRACTICE_MAX_CONCURRENT_ENVIRONMENTS.
    |
    */

    'lock_ttl_seconds' => (int) env('PRACTICE_ENV_LOCK_TTL_SECONDS', 120),

    'concurrency' => [
        'max_environments' => is_numeric(env('PRACTICE_MAX_CONCURRENT_ENVIRONMENTS'))
            ? (int) env('PRACTICE_MAX_CONCURRENT_ENVIRONMENTS')
            : null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Manager Whitelist
    |
    | Whitelist of PracticeEnvironmentManager implementations (the
    | same pattern as the payment gateways in config/payments.php and
    | the LLM clients in config/ai.php). The Docker isolation stage
    | (Stage 10) added the 'docker' entry: switching the driver is the
    | one-line PRACTICE_DRIVER change, nothing else.
    |
    */

    'managers' => [
        'local-sqlite' => LocalSqlitePracticeEnvironment::class,
        'docker' => DockerPracticeEnvironment::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | SQLite Runtime Limits
    |
    | The mandatory safety guards of LocalSqlitePracticeEnvironment
    | (docs/concept.md §5.4): a per-query timeout (detected after the
    | fact via hrtime — a synchronous PDO call cannot be interrupted),
    | a max JSON size of a returned result set, and a max database
    | size enforced on the engine level (PRAGMA max_page_count) so a
    | student cannot fill the disk.
    |
    */

    'sqlite' => [
        'timeout_seconds' => (int) env('PRACTICE_SQLITE_TIMEOUT_SECONDS', 5),
        'max_result_bytes' => (int) env('PRACTICE_SQLITE_MAX_RESULT_BYTES', 1_048_576),
        'max_db_bytes' => (int) env('PRACTICE_SQLITE_MAX_DB_BYTES', 104_857_600),
    ],

    /*
    |--------------------------------------------------------------------------
    | Docker Runtime (Stage 10)
    |
    | The isolation profile of DockerPracticeEnvironment: the engine
    | images per runtime (official mysql:8 / postgres:16 only —
    | custom images are out of scope), the per-container resource
    | limits (--memory/--cpus/--pids-limit, --network none), the
    | execution timeout (the Process facade hard-kills `docker exec`
    | shortly after it, then a post-factum duration check produces
    | the friendly verdict), the max size of the raw engine output,
    | the provision budget (the mysql image alone boots 10-30 s) and
    | the TTL of the hanging-container pruner. Completeness of this
    | section is boot-validated when PRACTICE_DRIVER=docker.
    |
    */

    'docker' => [
        'binary' => env('PRACTICE_DOCKER_BINARY', 'docker'),
        'runtimes' => [
            'mysql' => ['image' => 'mysql:8', 'engine_args' => ['-e', 'MYSQL_ROOT_PASSWORD=practice']],
            'postgres' => ['image' => 'postgres:16', 'engine_args' => ['-e', 'POSTGRES_PASSWORD=practice']],
        ],
        'memory_mb' => (int) env('PRACTICE_DOCKER_MEMORY_MB', 512),
        'cpus' => (float) env('PRACTICE_DOCKER_CPUS', 0.5),
        'pids_limit' => (int) env('PRACTICE_DOCKER_PIDS_LIMIT', 128),
        'timeout_seconds' => (int) env('PRACTICE_DOCKER_TIMEOUT_SECONDS', 20),
        'max_result_bytes' => (int) env('PRACTICE_DOCKER_MAX_RESULT_BYTES', 1_048_576),
        'provision_timeout_seconds' => (int) env('PRACTICE_DOCKER_PROVISION_TIMEOUT_SECONDS', 60),
        'prune' => ['ttl_minutes' => (int) env('PRACTICE_DOCKER_PRUNE_TTL_MINUTES', 30)],
    ],

    /*
    |--------------------------------------------------------------------------
    | Practice Storage
    |
    | Directory holding the disposable per-attempt SQLite files.
    | Created automatically on provision; the files are deleted in the
    | `finally` block of the practice action.
    |
    */

    'storage_path' => env('PRACTICE_STORAGE_PATH', storage_path('framework/practice')),

];
