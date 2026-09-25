<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Queue;

use PhpJobQueue\Idempotency\IdempotencyGuard;
use PhpJobQueue\Job\Job as QueueJob;
use PhpJobQueue\Persistence\FileStorage;
use PhpSystemsPlatform\Cache\CacheService;
use PhpSystemsPlatform\Domain\OrderService;
use PhpSystemsPlatform\Domain\SequentialOrderLoader;
use PhpSystemsPlatform\Observability\Trace;
use PhpSystemsPlatform\Storage\Database;
use PhpSystemsPlatform\Storage\Repositories\CatalogRepository;
use PhpSystemsPlatform\Storage\Repositories\InventoryRepository;
use PhpSystemsPlatform\Storage\Repositories\OrderRepository;

/**
 * The handler a queue WorkerPool runs - the worker's entry point into the
 * platform, and the counterpart of WorkerTasks on the worker-pool side.
 *
 * It runs inside a forked worker process, so everything it reaches has to be
 * built there too. Connections are created lazily, on the first job executed
 * in that worker, rather than copied from the parent: a fork would hand the
 * worker a connection the parent also thinks it owns, and two processes
 * answering on one socket is how protocols die. Rebuilding Database and
 * CacheService per worker keeps each worker's connection its own - the same
 * reason serve() builds its services fresh in its own process.
 *
 * started_at, completed_at and last_error (PLAN Step 19's job metadata) are
 * no longer recorded here: the component's own Job now carries them
 * directly (PhpJobQueue\Job\Job::$startedAt/$completedAt/$lastError),
 * stamped by JobDispatcher itself around the same dispatch this class
 * executes inside. This class only has to run the job; the metadata is the
 * caller's job object to persist.
 *
 * Step 20's idempotency guard is built here, per worker, the same lazy way
 * the connections are: a shared IdempotencyGuard over a FileStorage at the
 * configured `jobs.idempotency_store` path. Built once inside a worker, it
 * deduplicates by what it has already recorded in this process; because its
 * storage is the append-only journal other workers and past lives wrote, a
 * worker that starts after an operation settled sees the persisted key and
 * skips the redelivery - the "survives a restart" property Step 20 exists to
 * exercise. A worker without a configured store (null path) hands every job
 * a context with no guard, and nothing changes.
 */
final class JobExecutor
{
    private ?JobRegistry $registry = null;
    private ?OrderService $orders = null;
    private ?CacheService $cache = null;
    private ?Database $database = null;
    private ?SequentialOrderLoader $loader = null;
    private ?IdempotencyGuard $idempotency = null;
    private ?InventoryRepository $inventory = null;

    /**
     * @param array<string, mixed> $databaseConfig       the `database` config block
     * @param array<string, mixed> $cacheConfig          the `cache` config block
     * @param string|null          $idempotencyLogPath   the `jobs.idempotency_store` journal path, or null for no guard
     * @param Trace|null           $trace                PLAN Step 24's tracer, or null for none
     */
    public function __construct(
        private array $databaseConfig,
        private array $cacheConfig,
        private ?string $idempotencyLogPath = null,
        private readonly ?Trace $trace = null,
    ) {
    }

    public function __invoke(QueueJob $job): mixed
    {
        $context = new JobContext(
            $job,
            $this->orders(),
            $this->cache(),
            $this->loader(),
            $this->idempotency(),
            $this->inventory(),
        );

        // PLAN Step 24: a job whose payload names the request that published
        // it re-opens that request's trace scope on this worker before a
        // single read - so the database calls it makes AND the job.execute
        // span itself are all correlated back to the original HTTP request.
        // A hand-published job without a request_id runs exactly as it
        // always did; nothing is recorded for it.
        $requestId = $this->requestId($job->getPayload());

        if ($requestId !== null) {
            $this->trace?->beginRequest($requestId);
        }

        $startedAt = microtime(true);

        try {
            $this->registry ??= new JobRegistry();
            $this->registry->execute($job, $context);
        } catch (\Throwable $e) {
            $this->recordJobSpan($job, $startedAt, 'failed', $e->getMessage());
            throw $e;
        }

        $this->recordJobSpan($job, $startedAt);

        return null;
    }

    /**
     * The job execution span - one per attempt, so a retried job is the
     * same job_id on N consecutive spans under the same request_id. Who
     * ran it, how long it took and what happened come from the span's own
     * fields; the trace only records when an active scope exists.
     */
    private function recordJobSpan(QueueJob $job, float $startedAt, string $outcome = 'completed', ?string $error = null): void
    {
        $this->trace?->record('job.execute', microtime(true) - $startedAt, [
            'job_id' => (string) $job->getId(),
            'worker_pid' => getmypid(),
            'attempt' => $job->getAttempts(),
            'meta' => array_filter(
                ['outcome' => $outcome, 'error' => $error],
                static fn (mixed $value): bool => $value !== null,
            ),
        ]);
        $this->trace?->finishRequest();
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function requestId(array $payload): ?string
    {
        $requestId = $payload['request_id'] ?? null;

        return is_string($requestId) && $requestId !== '' ? $requestId : null;
    }

    /**
     * The sequential loader, on this worker's own connection: a job running
     * in a pool worker does its own reads instead of fanning them back into
     * the pool it is occupying a slot in.
     */
    private function loader(): SequentialOrderLoader
    {
        return $this->loader ??= new SequentialOrderLoader(
            $this->orders(),
            new CatalogRepository($this->database()),
        );
    }

    private function orders(): OrderService
    {
        return $this->orders ??= new OrderService(
            new OrderRepository($this->database()),
            null,
        );
    }

    private function database(): Database
    {
        return $this->database ??= Database::connect($this->databaseConfig, 10, null, $this->trace);
    }

    private function cache(): CacheService
    {
        return $this->cache ??= CacheService::fromConfig($this->cacheConfig);
    }

    private function idempotency(): ?IdempotencyGuard
    {
        if ($this->idempotencyLogPath === null) {
            return null;
        }

        return $this->idempotency ??= new IdempotencyGuard(new FileStorage($this->idempotencyLogPath));
    }

    private function inventory(): InventoryRepository
    {
        return $this->inventory ??= new InventoryRepository($this->database());
    }
}
