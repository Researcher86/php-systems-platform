<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Observability;

use InvalidArgumentException;

/**
 * PLAN Step 23's common event/metrics model: one place every component
 * reports its events into, keyed by the standard metric names the README
 * documents, and one snapshot() that turns them into numbers.
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
 * The names are constants rather than literals at the call sites so the
 * vocabulary of the platform is readable in one place and a typo is a
 * fatal error instead of a counter that silently stays at zero. The names
 * are the contract: components record into this registry, live sources
 * (queue depth, pool workers, memory) are pulled at snapshot time by
 * MetricsReporter, and everything reports under the same strings.
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

        $sample = $this->durations[$name] ?? null;

        // round() (not the raw division) so a sum of binary floats like
        // 0.1 + 0.3 does not leak 0.30000000000000004 into a dump.
        return $sample === null ? 0 : round($sample['total'] / $sample['count'], 6);
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
        $snapshot = [];

        foreach ($this->counters as $name => $value) {
            $snapshot[$name] = $value;
        }

        foreach ($this->gauges as $name => $value) {
            $snapshot[$name] = $value;
        }

        foreach ($this->durations as $name => $sample) {
            $snapshot[$name] = round($sample['total'] / $sample['count'], 6);
        }

        ksort($snapshot);

        return $snapshot;
    }
}
