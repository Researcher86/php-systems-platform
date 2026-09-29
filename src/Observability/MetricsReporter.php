<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Observability;

use PhpSystemsPlatform\Memory\MemoryReporter;
use PhpSystemsPlatform\Queue\QueueJournal;
use PhpWorkerPool\Sdk\WorkerPoolClient;

/**
 * The platform's one metrics reader: the standard metric set as one
 * snapshot, mixing what components push into the shared MetricsRegistry
 * with what only makes sense pulled fresh at read time.
 *
 *   registry            http.*, cache.*, db.* - accumulated in-process by
 *                       the components that touch them
 *   queue journal       queue.* - the durable journal is the observable
 *                       truth of the queue, read the same way queue:status
 *                       reads it
 *   worker pool stats   workers.*, worker.task_duration, worker.rss -
 *                       the Master answers them live, counting exactly the
 *                       workers a moment holds
 *   memory              process.rss - this process, right now
 *
 * The registry-owned names are always present (0 until first recorded), so
 * a fresh serve already answers with the full contract and a delta between
 * two reads never starts from a missing key. The other sources are
 * optional and appear only when wired; the pool is read best-effort - a pool
 * that will not answer leaves the workers.* lines out instead of failing
 * the whole read.
 */
final readonly class MetricsReporter
{
    /** The registry-owned standard metrics and their zero value. */
    private const array REGISTRY_DEFAULTS = [
        MetricsRegistry::HTTP_REQUESTS => 0,
        MetricsRegistry::HTTP_ERRORS => 0,
        MetricsRegistry::HTTP_REQUEST_DURATION => 0.0,
        MetricsRegistry::CACHE_HITS => 0,
        MetricsRegistry::CACHE_MISSES => 0,
        MetricsRegistry::CACHE_OPERATIONS => 0,
        MetricsRegistry::DB_OPERATIONS => 0,
        MetricsRegistry::DB_ERRORS => 0,
        MetricsRegistry::DB_OPERATION_DURATION => 0.0,
    ];

    public function __construct(
        private MetricsRegistry $registry,
        private ?QueueJournal $queueJournal = null,
        private ?WorkerPoolClient $pool = null,
        private ?MemoryReporter $memory = null,
    ) {
    }

    public function registry(): MetricsRegistry
    {
        return $this->registry;
    }

    /**
     * The full standard snapshot, name → value, sorted.
     *
     * @return array<string, int|float>
     */
    public function snapshot(): array
    {
        $snapshot = array_replace(
            self::REGISTRY_DEFAULTS,
            $this->registry->snapshot(),
            $this->queueSnapshot(),
            $this->poolSnapshot(),
        );

        if ($this->memory !== null) {
            $snapshot[MetricsRegistry::PROCESS_RSS] = $this->memory->snapshot()->rss ?? 0;
        }

        ksort($snapshot);

        return $snapshot;
    }

    /** @return array<string, int> */
    private function queueSnapshot(): array
    {
        if ($this->queueJournal === null) {
            return [];
        }

        $journal = $this->queueJournal->snapshot();

        return [
            MetricsRegistry::QUEUE_DEPTH => $journal['depth'],
            MetricsRegistry::QUEUE_PUBLISHED => $journal['published'],
            MetricsRegistry::QUEUE_COMPLETED => $journal['completed'],
            MetricsRegistry::QUEUE_FAILED => $journal['failed'],
            MetricsRegistry::QUEUE_RETRIED => $journal['retried'],
        ];
    }

    /** @return array<string, int|float> */
    private function poolSnapshot(): array
    {
        if ($this->pool === null) {
            return [];
        }

        try {
            $workers = $this->pool->stats();
        } catch (\Throwable) {
            return [];
        }

        $active = 0;
        $busy = 0;
        $idle = 0;
        $failed = 0;
        $taskSeconds = 0.0;
        $handledRequests = 0;
        $memory = [];

        foreach ($workers as $worker) {
            $state = (string) $worker['state'];

            if ($state === 'DEAD') {
                $failed++;

                continue;
            }

            $active++;

            if ($state === 'BUSY') {
                $busy++;
            } elseif ($state === 'IDLE') {
                $idle++;
            }

            // A worker that never ran a task has no time to average in.
            if ($worker['handledRequests'] > 0) {
                $handledRequests += $worker['handledRequests'];
                $taskSeconds += (float) $worker['workingSeconds'];
            }

            if ($worker['memoryBytes'] !== null) {
                $memory[] = $worker['memoryBytes'];
            }
        }

        $snapshot = [
            MetricsRegistry::WORKERS_ACTIVE => $active,
            MetricsRegistry::WORKERS_BUSY => $busy,
            MetricsRegistry::WORKERS_IDLE => $idle,
            MetricsRegistry::WORKERS_FAILED => $failed,
            MetricsRegistry::WORKER_TASK_DURATION => $handledRequests > 0 ? $taskSeconds / $handledRequests : 0.0,
        ];

        if ($memory !== []) {
            $snapshot[MetricsRegistry::WORKER_RSS] = (int) (array_sum($memory) / count($memory));
        }

        return $snapshot;
    }
}
