<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Queue;

use PhpJobQueue\Job\Job as QueueJob;
use PhpSystemsPlatform\Cache\CacheService;
use PhpSystemsPlatform\Domain\OrderService;
use PhpSystemsPlatform\Domain\SequentialOrderLoader;
use PhpSystemsPlatform\Storage\Database;
use PhpSystemsPlatform\Storage\Repositories\CatalogRepository;
use PhpSystemsPlatform\Storage\Repositories\OrderRepository;
use Throwable;

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
 * It is also the one chokepoint every execution attempt funnels through -
 * the real queue:consume path (via WorkerJobs) and the benchmark's
 * drainQueue() alike - which makes it the right place to record what the
 * queue component itself does not: started_at, completed_at and last_error
 * (PLAN Step 19's job metadata; see JobAttemptJournal for why the component
 * has none of these). $metadataLogPath is optional so a caller with nowhere
 * durable to put it (a test measuring something else entirely) can skip it
 * rather than be forced to supply one.
 */
final class JobExecutor
{
    private ?JobRegistry $registry = null;
    private ?OrderService $orders = null;
    private ?CacheService $cache = null;
    private ?Database $database = null;
    private ?SequentialOrderLoader $loader = null;
    private ?JobAttemptJournal $attempts = null;

    /**
     * @param array<string, mixed> $databaseConfig the `database` config block
     * @param array<string, mixed> $cacheConfig     the `cache` config block
     */
    public function __construct(
        private array $databaseConfig,
        private array $cacheConfig,
        private ?string $metadataLogPath = null,
    ) {
    }

    public function __invoke(QueueJob $job): mixed
    {
        $context = new JobContext($job, $this->orders(), $this->cache(), $this->loader());

        $this->registry ??= new JobRegistry();

        $startedAt = microtime(true);
        $error = null;

        try {
            $this->registry->execute($job, $context);
        } catch (Throwable $e) {
            $error = $e->getMessage();

            throw $e;
        } finally {
            $this->attemptJournal()?->record(
                $job->getId()->toString(),
                $job->getAttempts(),
                $startedAt,
                microtime(true),
                $error,
            );
        }

        return null;
    }

    private function attemptJournal(): ?JobAttemptJournal
    {
        if ($this->metadataLogPath === null) {
            return null;
        }

        return $this->attempts ??= new JobAttemptJournal($this->metadataLogPath);
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
        return $this->database ??= Database::connect($this->databaseConfig);
    }

    private function cache(): CacheService
    {
        return $this->cache ??= CacheService::fromConfig($this->cacheConfig);
    }
}
