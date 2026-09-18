<?php

declare(strict_types=1);

namespace Tests\Unit\Providers;

use App\Providers\AppServiceProvider;
use App\Services\Practice\Docker\DockerPracticeEnvironment;
use App\Services\Practice\LocalSqlitePracticeEnvironment;
use App\Services\Practice\PracticeEnvironmentManager;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class PracticeEnvironmentManagerBindingTest extends TestCase
{
    public function test_binds_local_sqlite_manager_from_config(): void
    {
        config(['practice.driver' => 'local-sqlite']);

        $manager = app(PracticeEnvironmentManager::class);

        $this->assertInstanceOf(LocalSqlitePracticeEnvironment::class, $manager);
        $this->assertSame($manager, app(PracticeEnvironmentManager::class));
    }

    public function test_binds_docker_manager_from_config(): void
    {
        config(['practice.driver' => 'docker']);
        $this->app->forgetInstance(PracticeEnvironmentManager::class);

        // The binding captures the manager class at register time, so
        // the driver switch is replayed through a re-register — which
        // also walks the dockerConfigured() boot validation with the
        // default complete config.
        (new AppServiceProvider($this->app))->register();

        $manager = app(PracticeEnvironmentManager::class);

        // The identity check runs before the class narrowing — with
        // the narrowing first, PHPStan flags the second narrowed
        // assertSame of this file with an unresolvable-type false
        // positive (the identical local-sqlite shape above is clean).
        $this->assertSame($manager, app(PracticeEnvironmentManager::class));
        $this->assertInstanceOf(DockerPracticeEnvironment::class, $manager);
    }

    public function test_unknown_driver_fails_loud_on_rebind(): void
    {
        config(['practice.driver' => 'bogus']);
        $this->app->forgetInstance(PracticeEnvironmentManager::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Practice environment driver [bogus] is not whitelisted in config/practice.php');

        (new AppServiceProvider($this->app))->register();
    }

    /**
     * The docker driver boot-validates the completeness of the
     * practice.docker section (the YooKassa credentials pattern);
     * every hole below must crash the boot instead of resolving.
     *
     * @param  array<string, mixed>  $dockerOverride  the broken config slice
     */
    #[DataProvider('incompleteDockerConfigProvider')]
    public function test_docker_driver_with_incomplete_config_fails_loud_on_rebind(array $dockerOverride): void
    {
        config([
            'practice.driver' => 'docker',
            'practice.docker' => [...config('practice.docker'), ...$dockerOverride],
        ]);
        $this->app->forgetInstance(PracticeEnvironmentManager::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Practice driver [docker] requires a complete practice.docker config');

        (new AppServiceProvider($this->app))->register();
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function incompleteDockerConfigProvider(): array
    {
        return [
            'empty binary' => [['binary' => '']],
            'binary not a string' => [['binary' => null]],
            'missing mysql image' => [['runtimes' => ['mysql' => ['image' => ''], 'postgres' => ['image' => 'postgres:16']]]],
            'missing postgres runtime' => [['runtimes' => ['mysql' => ['image' => 'mysql:8']]]],
            'zero memory' => [['memory_mb' => 0]],
            'zero cpus' => [['cpus' => 0]],
            'zero pids limit' => [['pids_limit' => 0]],
            'zero timeout' => [['timeout_seconds' => 0]],
            'zero max result bytes' => [['max_result_bytes' => 0]],
            'zero provision timeout' => [['provision_timeout_seconds' => 0]],
        ];
    }
}
