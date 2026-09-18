<?php

declare(strict_types=1);

namespace App\Services\Practice\Docker;

use App\Services\Practice\Docker\Dto\DockerExecResult;
use RuntimeException;

/**
 * Internal contract for controlling the Docker Engine from the practice
 * subsystem (Stage 10). This is an implementation detail of the docker
 * driver — do not confuse it with the PracticeEnvironmentManager
 * contract it serves. The single implementation, CliDockerClient, talks
 * to the `docker` CLI via the Laravel Process facade; mocking this
 * interface is what keeps the driver's unit tests independent of a
 * running Docker daemon.
 */
interface DockerClient
{
    /**
     * Start a detached container of the given image under the resource
     * limits and the itlearns practice labels.
     *
     * @param  string  $image  official engine image (mysql:8, postgres:16 — the whitelist lives in practice.docker.runtimes)
     * @param  string  $name  unique container name (itlearns-practice-{uuid})
     * @param  list<string>  $engineArgs  extra `docker run` arguments of the runtime (env vars of the engine image)
     * @param  int  $memoryMb  hard memory limit (--memory {n}m)
     * @param  float  $cpus  CPU quota (--cpus)
     * @param  int  $pidsLimit  process count limit (--pids-limit)
     * @return string the container id (trimmed stdout of docker run)
     *
     * @throws RuntimeException when the container could not be started (missing image, daemon down, name clash)
     */
    public function startContainer(string $image, string $name, array $engineArgs, int $memoryMb, float $cpus, int $pidsLimit): string;

    /**
     * Run a command inside a running container, optionally feeding it
     * on stdin. A non-zero exit code of the in-container command is a
     * normal outcome reported through the result. exec() never throws:
     * an infrastructure failure to even start the docker binary is
     * reported as exit code 127 with the reason in errorOutput, and a
     * locally hard-killed run (timeout) as exit code 124 — the error
     * channel of the result is the single failure surface.
     *
     * @param  string  $containerId  target container id
     * @param  list<string>  $command  argv of the in-container command (engine client invocation)
     * @param  string|null  $stdin  payload piped into the command (SQL), null for no stdin
     * @param  int  $timeoutSeconds  budget for the in-container command; the local `docker exec` is hard-killed slightly after it
     */
    public function exec(string $containerId, array $command, ?string $stdin, int $timeoutSeconds): DockerExecResult;

    /**
     * Force-remove a container (docker rm -f). Failures are suppressed
     * on purpose: removing an already-absent container must not fail,
     * and neither may a failure to even start the docker binary —
     * destroy() runs in the caller's finally and relies on this
     * idempotency, so an escaping exception could mask the primary
     * failure.
     */
    public function removeContainer(string $containerId): void;

    /**
     * List every container carrying the itlearns practice label,
     * including stopped ones — the discovery surface of the pruner.
     *
     * @return list<array{container_id: string, created_at: string|null}>
     *
     * @throws RuntimeException when `docker ps` itself fails (daemon down)
     */
    public function listLabeled(): array;
}
