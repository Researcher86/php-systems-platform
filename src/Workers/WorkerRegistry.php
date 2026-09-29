<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Workers;

use PhpJobQueue\Job\JobState;
use PhpJobQueue\Support\Clock;
use PhpJobQueue\Support\SystemClock;
use PhpJobQueue\Worker\Worker;
use PhpJobQueue\Worker\WorkerPool;
use PhpSystemsPlatform\Queue\JournalTail;
use PhpSystemsPlatform\Queue\QueueJournal;
use RuntimeException;

/**
 * The queue consumer's own worker lifecycle, made observable (PLAN Step 11).
 *
 * php-worker-pool keeps its workers' states private inside the Master, so
 * the lifecycle the platform can truthfully observe is the one it owns: the
 * php-job-queue forwarders queue:consume forks. Pid, state, current job and
 * the tasks_completed/tasks_failed counters are read straight off those
 * in-process Worker objects.
 *
 * The journal answers what Worker's counters cannot: WHICH job resolved and
 * WHEN (resolvedAt()/resolvedCount()), for per-job latency (QueueBenchmark).
 */
final class WorkerRegistry
{
    private const float SNAPSHOT_INTERVAL = 0.5;

    /** How often resolvedAt() is pruned - a scan of the whole map, so not every tick. */
    private const float PRUNE_INTERVAL = 0.05;

    /**
     * How long a resolution stays in resolvedAt(). A reader must poll within
     * this window or copy the value out (QueueBenchmark copies it on the next
     * tick); without a bound the map would grow with throughput for the life
     * of the consumer.
     */
    private const float RESOLVED_TTL_SECONDS = 300.0;

    /**
     * workerId => worker snapshot.
     *
     * @var array<int, array{pid: int, state: string, current_job: ?string, started_at: float, tasks_completed: int, tasks_failed: int}>
     */
    private array $workers = [];

    /**
     * Job ids dispatched but not yet seen terminal in the journal. A set, not
     * keyed by worker: a worker moves on to its next delivery while an
     * earlier one may still await its terminal row.
     *
     * @var array<string, true>
     */
    private array $inFlight = [];

    /** @var array<string, float> job id => wall time it was credited COMPLETED */
    private array $resolvedAt = [];

    private int $resolvedCount = 0;

    private float $lastPruneAt = 0.0;

    private float $nextSnapshotAt = 0.0;

    /** Time-weighted sum of busy workers: Σ (busy count × seconds between samples). */
    private float $busySeconds = 0.0;

    private float $lastSampleAt = 0.0;

    private readonly JournalTail $tail;

    public function __construct(
        private readonly WorkerPool $pool,
        QueueJournal $journal,
        private readonly Clock $clock = new SystemClock(),
        private readonly string $statusPath = '',
        private readonly float $resolvedTtl = self::RESOLVED_TTL_SECONDS,
    ) {
        // Tailed, not replayed: settle() runs every tick, and a full replay of
        // a never-compacted journal would cost the whole queue history each time.
        $this->tail = new JournalTail($journal->logPath());
    }

    /**
     * Record the workers' state and which job each one is carrying, called
     * after a dispatch batch went out and before the answers are collected.
     */
    public function capture(): void
    {
        $now = $this->clock->now();
        $busy = 0;

        foreach ($this->pool->getWorkers() as $worker) {
            $this->observe($worker);
            $job = $worker->getCurrentJob();

            if ($job !== null) {
                $busy++;
                $this->inFlight[$job->getId()->toString()] = true;
            }
        }

        // Utilization: the busy count weighted by the time since the previous sample.
        if ($this->lastSampleAt > 0.0) {
            $this->busySeconds += ($now - $this->lastSampleAt) * $busy;
        }

        $this->lastSampleAt = $now;
    }

    /**
     * Credit the jobs that reached a terminal state, called after the answers
     * were applied. A job whose terminal row is not journaled yet stays in
     * flight until a later settle().
     */
    public function settle(): void
    {
        $now = $this->clock->now();

        foreach ($this->pool->getWorkers() as $worker) {
            $this->observe($worker);
        }

        foreach ($this->tail->read() as $jobId => $row) {
            if (!isset($this->inFlight[$jobId])) {
                continue;
            }

            $state = JobState::fromName((string) $row['state']);

            if (!$state->isTerminal()) {
                continue;
            }

            if ($state === JobState::COMPLETED) {
                $this->resolvedAt[$jobId] = $now;
            }

            $this->resolvedCount++;
            unset($this->inFlight[$jobId]);
        }

        $this->pruneResolvedAt($now);
    }

    /**
     * The current per-worker snapshot, keyed by worker id.
     *
     * @return array<int, array{id: int, pid: int, state: string, current_job: ?string, started_at: float, tasks_completed: int, tasks_failed: int}>
     */
    public function snapshot(): array
    {
        $snapshot = [];

        foreach ($this->workers as $id => $worker) {
            $snapshot[$id] = ['id' => $id] + $worker;
        }

        return $snapshot;
    }

    /**
     * Time-weighted busy time, for worker utilization: Σ(busy workers × Δt).
     */
    public function busySeconds(): float
    {
        return $this->busySeconds;
    }

    /**
     * The wall time a job was credited COMPLETED, or null if it has not been
     * (or was pruned after the TTL).
     */
    public function resolvedAt(string $jobId): ?float
    {
        return $this->resolvedAt[$jobId] ?? null;
    }

    /**
     * How many jobs have reached a terminal state (completed or failed) - a
     * benchmark's "everything is processed" signal.
     */
    public function resolvedCount(): int
    {
        return $this->resolvedCount;
    }

    /**
     * Write the snapshot to the status file if the interval has elapsed, so
     * `workers:status` and `GET /workers` read a recent view without the
     * consumer having to answer anything.
     */
    public function maybeWrite(): void
    {
        if ($this->statusPath === '' || $this->clock->now() < $this->nextSnapshotAt) {
            return;
        }

        $this->write();
        $this->nextSnapshotAt = $this->clock->now() + self::SNAPSHOT_INTERVAL;
    }

    /**
     * Force a write - called once more after the consumer stops, so the file
     * shows the workers' final states (DRAINING/STOPPING/DEAD).
     */
    public function write(): void
    {
        if ($this->statusPath === '') {
            return;
        }

        $encoded = json_encode(
            array_values($this->snapshot()),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        );

        if ($encoded === false) {
            throw new RuntimeException('Cannot encode worker status as JSON.');
        }

        if (@file_put_contents($this->statusPath, $encoded . "\n") === false) {
            throw new RuntimeException(sprintf('Could not write worker status to "%s".', $this->statusPath));
        }
    }

    private function pruneResolvedAt(float $now): void
    {
        if ($now - $this->lastPruneAt < self::PRUNE_INTERVAL) {
            return;
        }

        $this->lastPruneAt = $now;
        $cutoff = $now - $this->resolvedTtl;

        foreach ($this->resolvedAt as $jobId => $at) {
            if ($at < $cutoff) {
                unset($this->resolvedAt[$jobId]);
            }
        }
    }

    /**
     * Keep one row per worker id current. php-job-queue reuses the id for a
     * replacement worker, so a pid change restarts started_at; the task
     * counters reset by themselves (the replacement is a new Worker object).
     */
    private function observe(Worker $worker): void
    {
        $id = $worker->getId();
        $pid = $worker->getPid();
        $previous = $this->workers[$id] ?? null;

        $this->workers[$id] = [
            'pid' => $pid,
            'state' => $worker->getState()->name,
            'current_job' => $worker->getCurrentJob()?->getId()->toString(),
            'started_at' => $previous !== null && $previous['pid'] === $pid ? $previous['started_at'] : $this->clock->now(),
            'tasks_completed' => $worker->getTasksCompleted(),
            'tasks_failed' => $worker->getTasksFailed(),
        ];
    }
}
