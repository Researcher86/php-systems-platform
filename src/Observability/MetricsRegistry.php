<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Observability;

use InvalidArgumentException;

/**
 * The shared in-process metrics model: components report into it under the
 * standard names the README documents, and snapshot() turns them into
 * numbers.
 *
 * Three kinds of metric share the registry:
 *
 *   counters   increment()   monotonic since the process started - how many
 *                            requests, how many cache misses
 *   gauges     gauge()       a number that is only current, not cumulative -
 *                            the latest value wins, there is no history
 *   durations  observe()     a latency sample in seconds; the registry keeps
 *                            sum+count and snapshot() reports the average,
 *                            which is the only rendering a single name like
 *                            http.request_duration can carry
 *
 * The names are constants so a typo is a fatal error instead of a counter
 * that silently stays at zero. A metric appears in snapshot() only once
 * something recorded it; MetricsReporter fills in the rest of the standard
 * set.
 */
final class MetricsRegistry
{
    public const string HTTP_REQUESTS = 'http.requests';

    public const string HTTP_ERRORS = 'http.errors';

    public const string HTTP_REQUEST_DURATION = 'http.request_duration';

    public const string CACHE_HITS = 'cache.hit';

    public const string CACHE_MISSES = 'cache.miss';

    public const string CACHE_OPERATIONS = 'cache.operations';

    public const string DB_OPERATIONS = 'db.operations';

    public const string DB_ERRORS = 'db.errors';

    public const string DB_OPERATION_DURATION = 'db.operation_duration';

    public const string QUEUE_DEPTH = 'queue.depth';

    public const string QUEUE_PUBLISHED = 'queue.published';

    public const string QUEUE_COMPLETED = 'queue.completed';

    public const string QUEUE_FAILED = 'queue.failed';

    public const string QUEUE_RETRIED = 'queue.retried';

    public const string WORKERS_ACTIVE = 'workers.active';

    public const string WORKERS_BUSY = 'workers.busy';

    public const string WORKERS_IDLE = 'workers.idle';

    public const string WORKERS_FAILED = 'workers.failed';

    public const string WORKER_TASK_DURATION = 'worker.task_duration';

    public const string PROCESS_RSS = 'process.rss';

    public const string WORKER_RSS = 'worker.rss';

    /** @var array<string, int> */
    private array $counters = [];

    /** @var array<string, int|float> */
    private array $gauges = [];

    /** @var array<string, array{count: int, total: float}> */
    private array $durations = [];

    public function increment(string $name, int $by = 1): void
    {
        $this->counters[$name] = ($this->counters[$name] ?? 0) + $by;
    }

    public function gauge(string $name, int|float $value): void
    {
        $this->gauges[$name] = $value;
    }

    public function observe(string $name, float $seconds): void
    {
        if ($seconds < 0) {
            throw new InvalidArgumentException('Duration cannot be negative');
        }

        $sample = $this->durations[$name] ?? ['count' => 0, 'total' => 0.0];
        $sample['count']++;
        $sample['total'] += $seconds;
        $this->durations[$name] = $sample;
    }

    /**
     * The current value of one metric: a counter or gauge as recorded, a
     * duration as its running average in seconds (0.0 before any sample).
     */
    public function get(string $name): int|float
    {
        if (isset($this->counters[$name])) {
            return $this->counters[$name];
        }

        if (array_key_exists($name, $this->gauges)) {
            return $this->gauges[$name];
        }

        return isset($this->durations[$name]) ? $this->average($name) : 0;
    }

    /**
     * Every metric this registry holds, name → value, alphabetically sorted
     * so a dump is stable. Counters are ints, gauges int|float, durations
     * their running average in seconds.
     *
     * @return array<string, int|float>
     */
    public function snapshot(): array
    {
        $snapshot = $this->counters;

        foreach ($this->gauges as $name => $value) {
            $snapshot[$name] = $value;
        }

        foreach (array_keys($this->durations) as $name) {
            $snapshot[$name] = $this->average($name);
        }

        ksort($snapshot);

        return $snapshot;
    }

    /**
     * Rounded so a sum of binary floats like 0.1 + 0.3 does not leak
     * 0.30000000000000004 into a dump.
     */
    private function average(string $name): float
    {
        $sample = $this->durations[$name];

        return round($sample['total'] / $sample['count'], 6);
    }
}
