<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Workers;

use PhpJobQueue\Dispatcher\JobDispatcher;
use PhpJobQueue\Job\Job;
use PhpJobQueue\Metrics\MetricsCollector;
use PhpJobQueue\Persistence\FileStorage;
use PhpJobQueue\Producer\JobFactory;
use PhpJobQueue\Producer\Producer;
use PhpJobQueue\Queue\InMemoryQueue;
use PhpJobQueue\Retry\FixedDelayRetry;
use PhpJobQueue\Support\Clock;
use PhpJobQueue\Support\SystemClock;
use PhpJobQueue\Worker\WorkerPool;
use PhpSystemsPlatform\Queue\Jobs\NoopJob;
use PhpSystemsPlatform\Queue\QueueConsumer;
use PhpSystemsPlatform\Queue\QueueJournal;
use PhpWorkerPool\Sdk\WorkerPoolClient;
use RuntimeException;

/**
 * One workload run over the real queue pipeline - PLAN Step 12: publish
 * $jobs READY jobs into an isolated journal, drive the queue consumer loop
 * (forwarders → WorkerManager → a fixed-size pool), and measure how long the
 * whole queue → consumer → pool → job path took.
 *
 * The point of the number is the comparison: doubling the pool's workers
 * does not halve the time, because a queue job is a request round-trip with
 * shared cost - the pool Master, the socket, the workers' own database reads.
 * Three runs (100/4, 1000/4, 1000/8) show that the queue is not a pure
 * parallelism multiplier.
 *
 * Latency is per-job end to end: from the moment the job was appended to the
 * journal until the registry sees its COMPLETED row there. Worker utilization
 * is the registry's time-weighted busy share of the pool's workers over the
 * wall time.
 */
final class QueueBenchmark
{
    private ?WorkerRegistry $registry = null;

    public function __construct(
        private string $logPath,
        private string $socketPath,
        private int $forwarders,
        private Clock $clock = new SystemClock(),
    ) {
    }

    /**
     * @return array{jobs: int, workers: int, wall_seconds: float, throughput_per_sec: float, avg_latency_ms: float, p95_latency_ms: float, queue_depth: int, worker_utilization: float}
     */
    public function run(int $jobs, int $workers): array
    {
        // The clock starts before the first publish, so total processing time
        // spans publish → queue → pool → completed and latency always fits
        // inside it.
        $startedAt = microtime(true);
        $publishedAt = $this->publish($jobs);
        $consumer = $this->consumer(array_keys($publishedAt));

        $deadline = $startedAt + max(60.0, $jobs * 0.2);
        $completedAt = [];

        $consumer->runWhile(function () use (&$completedAt, $publishedAt, $deadline): bool {
            // The registry credits a completion the tick its answer lands;
            // copy it out before its TTL could prune it. The loop runs until
            // every job is terminal, completed or failed.
            foreach (array_keys($publishedAt) as $id) {
                if (!isset($completedAt[$id])) {
                    $resolvedAt = $this->registry?->resolvedAt($id);

                    if ($resolvedAt !== null) {
                        $completedAt[$id] = $resolvedAt;
                    }
                }
            }

            return microtime(true) < $deadline
                && ($this->registry?->resolvedCount() ?? 0) < count($publishedAt);
        });

        $wall = microtime(true) - $startedAt;

        if (count($completedAt) < count($publishedAt)) {
            throw new RuntimeException(sprintf(
                'Benchmark did not complete cleanly: %d of %d jobs completed, %d failed.',
                count($completedAt),
                count($publishedAt),
                count($publishedAt) - count($completedAt),
            ));
        }

        $latencies = [];

        foreach ($publishedAt as $id => $at) {
            $latencies[] = $completedAt[$id] - $at;
        }

        sort($latencies);
        $count = count($latencies);
        $avg = array_sum($latencies) / $count;
        $p95 = $latencies[(int) floor(0.95 * ($count - 1))];
        $utilization = min(1.0, ($this->registry?->busySeconds() ?? 0.0) / max(1e-9, $workers * $wall));

        return [
            'jobs' => $jobs,
            'workers' => $workers,
            'wall_seconds' => round($wall, 4),
            'throughput_per_sec' => round($jobs / max(1e-9, $wall), 1),
            'avg_latency_ms' => round($avg * 1_000, 2),
            'p95_latency_ms' => round($p95 * 1_000, 2),
            'queue_depth' => $jobs,
            'worker_utilization' => round($utilization, 3),
        ];
    }

    /**
     * Append one bench.noop job per slot to the isolated journal, remembering
     * each job's publication time.
     *
     * @return array<string, float> job id => published unix timestamp
     */
    private function publish(int $jobs): array
    {
        $publishedAt = [];
        $producer = new Producer(
            new InMemoryQueue($this->clock, new FileStorage($this->logPath)),
            new JobFactory($this->clock, new MetricsCollector()),
        );

        for ($i = 0; $i < $jobs; $i++) {
            $job = $producer->dispatch(
                NoopJob::TYPE,
                maxAttempts: 3,
            );

            $publishedAt[$job->getId()->toString()] = $this->clock->now();
        }

        return $publishedAt;
    }

    /**
     * The consumer loop over the freshly published jobs: restore the journal,
     * fork $forwarders forwarders to the fixed pool, and attribute outcomes
     * through a registry.
     *
     * @param list<string> $jobIds
     */
    private function consumer(array $jobIds): QueueConsumer
    {
        $queue = InMemoryQueue::restoreFromStorage(new FileStorage($this->logPath), $this->clock);

        $workerManager = null;
        $pool = new WorkerPool(
            size: $this->forwarders,
            handler: function (Job $job) use (&$workerManager): mixed {
                $workerManager ??= new WorkerManager(new WorkerPoolClient($this->socketPath, 30.0));

                $workerManager->execute($job);

                return null;
            },
        );

        $this->registry = new WorkerRegistry($pool, new QueueJournal($this->logPath));
        $dispatcher = new JobDispatcher(
            queue: $queue,
            workerPool: $pool,
            retryPolicy: new FixedDelayRetry(0),
            clock: $this->clock,
            visibilityTimeout: 30,
            storage: new FileStorage($this->logPath),
            metrics: new MetricsCollector(),
        );

        return new QueueConsumer(
            dispatcher: $dispatcher,
            queue: $queue,
            logPath: $this->logPath,
            clock: $this->clock,
            maxWait: 0.01,
            shutdownGrace: 10.0,
            registry: $this->registry,
            knownIds: array_fill_keys($jobIds, true),
        );
    }
}
