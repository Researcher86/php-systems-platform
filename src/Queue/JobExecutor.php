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
use Throwable;

/**
 * The handler a queue worker runs - the worker's entry point into the
 * platform, and the counterpart of WorkerTasks on the worker-pool side.
 *
 * It runs inside a forked worker process, so every connection is built
 * lazily there, on the first job, never inherited from the parent: two
 * processes speaking on one socket is how protocols die.
 *
 * Job metadata (started_at/completed_at/last_error) is stamped by the
 * component's JobDispatcher on its own Job; this class only runs the job.
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

    /** stat of the idempotency store the current guard was loaded from */
    private ?string $idempotencyStat = null;

    /**
     * @param array<string, mixed> $databaseConfig     the `database` config block
     * @param array<string, mixed> $cacheConfig        the `cache` config block
     * @param string|null          $idempotencyLogPath the `jobs.idempotency_store` journal path, or null for no guard
     * @param Trace|null           $trace              PLAN Step 24's tracer, or null for none
     */
    public function __construct(
        private readonly array $databaseConfig,
        private readonly array $cacheConfig,
        private readonly ?string $idempotencyLogPath = null,
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
            $this->idempotency($job),
            $this->inventory(),
        );

        // A job whose payload names the request that published it re-opens
        // that request's trace scope here, so its database calls and its own
        // job.execute span correlate back to the original HTTP request.
        // Without a request_id nothing is recorded (Trace::record() needs a scope).
        $requestId = $job->getPayload()['request_id'] ?? null;

        if (is_string($requestId) && $requestId !== '') {
            $this->trace?->beginRequest($requestId);
        }

        $startedAt = microtime(true);

        try {
            $this->registry ??= new JobRegistry();
            $this->registry->execute($job, $context);
        } catch (Throwable $e) {
            $this->recordJobSpan($job, $startedAt, ['outcome' => 'failed', 'error' => $e->getMessage()]);

            throw $e;
        }

        $this->recordJobSpan($job, $startedAt, ['outcome' => 'completed']);

        return null;
    }

    /**
     * One span per attempt, so a retried job is the same job_id on N
     * consecutive spans under the same request_id.
     *
     * @param array<string, string> $meta
     */
    private function recordJobSpan(QueueJob $job, float $startedAt, array $meta): void
    {
        $this->trace?->record('job.execute', microtime(true) - $startedAt, [
            'job_id' => (string) $job->getId(),
            'worker_pid' => getmypid(),
            'attempt' => $job->getAttempts(),
            'meta' => $meta,
        ]);
        $this->trace?->finishRequest();
    }

    /**
     * The sequential loader: a job already occupies a pool slot, so it does
     * its own reads instead of fanning them back into that pool.
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

    /**
     * The guard over the shared `jobs.idempotency_store` journal.
     *
     * IdempotencyGuard loads its keys only when constructed, and every pool
     * worker appends to the same store. A guard kept for the worker's whole
     * life would therefore miss a key a SIBLING recorded later - exactly the
     * redelivery it exists for (a worker settles, dies before its ACK, and
     * the job lands on another, long-running worker). So before a keyed job
     * the guard is reloaded whenever the store changed since it was built.
     */
    private function idempotency(QueueJob $job): ?IdempotencyGuard
    {
        if ($this->idempotencyLogPath === null) {
            return null;
        }

        if ($job->getIdempotencyKey() !== null) {
            clearstatcache(true, $this->idempotencyLogPath);
            $stat = @stat($this->idempotencyLogPath);
            $key = $stat === false ? 'missing' : $stat['size'] . ':' . $stat['mtime'];

            // Stat before load: a write landing in between is loaded now and
            // merely triggers one extra reload next time.
            if ($key !== $this->idempotencyStat) {
                $this->idempotencyStat = $key;
                $this->idempotency = null;
            }
        }

        return $this->idempotency ??= new IdempotencyGuard(new FileStorage($this->idempotencyLogPath));
    }

    private function inventory(): InventoryRepository
    {
        return $this->inventory ??= new InventoryRepository($this->database());
    }
}
