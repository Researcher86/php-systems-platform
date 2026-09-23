<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Queue;

use PhpJobQueue\Job\Job as QueueJob;
use PhpMiniDatabase\Client\ClientConfig;
use PhpSystemsPlatform\Cache\CacheService;
use PhpSystemsPlatform\Domain\OrderService;
use PhpSystemsPlatform\Domain\SequentialOrderLoader;
use PhpSystemsPlatform\Storage\Database;
use PhpSystemsPlatform\Storage\Repositories\CatalogRepository;
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
 */
final class JobExecutor
{
    private ?JobRegistry $registry = null;
    private ?OrderService $orders = null;
    private ?CacheService $cache = null;
    private ?Database $database = null;
    private ?SequentialOrderLoader $loader = null;

    /**
     * @param array<string, mixed> $databaseConfig the `database` config block
     * @param array<string, mixed> $cacheConfig     the `cache` config block
     */
    public function __construct(
        private array $databaseConfig,
        private array $cacheConfig,
    ) {
    }

    public function __invoke(QueueJob $job): mixed
    {
        $context = new JobContext($job, $this->orders(), $this->cache(), $this->loader());

        $this->registry ??= new JobRegistry();
        $this->registry->execute($job, $context);

        return null;
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
        return $this->database ??= Database::fromConfig(new ClientConfig(
            host: (string) $this->databaseConfig['host'],
            port: (int) $this->databaseConfig['port'],
            connectTimeoutSeconds: (float) $this->databaseConfig['timeout'],
        ));
    }

    private function cache(): CacheService
    {
        return $this->cache ??= CacheService::fromConfig($this->cacheConfig);
    }
}
