<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Observability;

use PhpSystemsPlatform\Memory\MemoryReporter;
use PhpSystemsPlatform\Queue\QueueJournal;
use PhpWorkerPool\Sdk\WorkerPoolClient;

/**
 * PLAN Step 23's one reader for the whole platform: the standard metric set
 * as one snapshot, mixing what components push into the shared MetricsRegistry
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
 * Every source is optional: a snapshot answers with only the sources it has.
 * The pool client is the fragile one (a connecting client, a pool that died),
 * so its stats are taken best-effort - a pool that will not answer simply
 * leaves the workers.* lines out of the snapshot instead of failing the
 * whole read.
 */
final readonly class MetricsReporter
{
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
     * The full standard snapshot, name → value, sorted. Counters and gauges
     * carry their recorded values; durations carry their running average in
     * seconds; queue/worker/memory numbers are read from their live sources
     * at call time.
     *
     * @return array<string, int|float>
     */
    public function snapshot(): array
    {
        $snapshot = $this->registry->snapshot();

        foreach ($this->queueSnapshot() as $name => $value) {
            $snapshot[$name] = $value;
        }

        foreach ($this->poolSnapshot() as $name => $value) {
            $snapshot[$name] = $value;
        }

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
