<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Workers;

use PhpJobQueue\Job\JobState;
use PhpJobQueue\Support\Clock;
use PhpJobQueue\Support\SystemClock;
use PhpJobQueue\Worker\Worker;
use PhpJobQueue\Worker\WorkerPool;
use PhpSystemsPlatform\Queue\QueueJournal;
use RuntimeException;

/**
 * The queue consumer's own worker lifecycle, made observable (PLAN Step 11).
 *
 * php-worker-pool keeps its workers' states private inside the Master, with
 * no client channel to read them, so the lifecycle the platform can truthfully
 * observe is the one it owns: the php-job-queue forwarders that queue:consume
 * forks. Each has a real pid, a real state (STARTING/IDLE/BUSY/DRAINING/
 * STOPPING/DEAD), and a current job while busy - all read straight off the
 * Worker objects the consumer owns in-process.
 *
 * The two counters the component does not expose are attributed here from
 * the journal - the durable source of truth. The consumer's loop calls
 * capture() right after dispatching a batch and settle() after the answers
 * land. Between those two calls a worker is BUSY and its current job is
 * known (state in the parent only moves once collect() is called), so every
 * dispatched job is captured; settle() then reads that job's terminal state
 * from the journal and credits the worker that held it. A job that was
 * retried is credited to whichever worker delivered its final attempt.
 */
final class WorkerRegistry
{
    private const float SNAPSHOT_INTERVAL = 0.5;

    /**
     * workerId => worker snapshot.
     *
     * @var array<int, array{pid: int, state: string, current_job: ?string, started_at: float, tasks_completed: int, tasks_failed: int}>
     */
    private array $workers = [];

    /**
     * job id => worker id carrying it, awaiting a terminal state. Keyed by
     * the JOB, not the worker: a worker moves on to the next delivery while
     * an earlier one waits for the journal to show its terminal state, and
     * keying by worker would drop that earlier job (it would be overwritten
     * by the next delivery).
     *
     * @var array<string, int>
     */
    private array $inFlight = [];

    /** @var array<string, float> job id => wall time it was credited COMPLETED */
    private array $resolvedAt = [];

    private int $resolvedCount = 0;

    private const float JOURNAL_REFRESH_SECONDS = 0.05;

    /** @var array<string, array<string, mixed>>|null */
    private ?array $rowsCache = null;

    private float $rowsCacheAt = 0.0;

    private float $nextSnapshotAt = 0.0;

    /** Time-weighted sum of busy workers: Σ (busy count × seconds between samples). */
    private float $busySeconds = 0.0;

    private float $lastSampleAt = 0.0;

    public function __construct(
        private WorkerPool $pool,
        private QueueJournal $journal,
        private Clock $clock = new SystemClock(),
        private string $statusPath = '',
    ) {
    }

    /**
     * Record the workers' state and which job each one is carrying, called
     * after a dispatch batch went out and before the answers are collected.
     */
    public function capture(): void
    {
        $now = $this->clock->now();

        // Utilization sampling: weight the number of busy workers by the time
        // since the previous sample, so busySeconds() is Σ(busy × Δt).
        if ($this->lastSampleAt > 0.0) {
            $this->busySeconds += ($now - $this->lastSampleAt) * $this->busyCount();
        }

        $this->lastSampleAt = $now;

        foreach ($this->pool->getWorkers() as $worker) {
            $this->observe($worker);

            if (!$worker->isWorking()) {
                continue;
            }

            $job = $worker->getCurrentJob();

            if ($job !== null) {
                $this->inFlight[$job->getId()->toString()] = $worker->getId();
            }
        }
    }

    /**
     * Credit completed and failed jobs to the workers that delivered them,
     * called after the answers were applied. A job whose terminal state has
     * not been journaled yet stays in flight until a later settle().
     */
    public function settle(): void
    {
        foreach ($this->pool->getWorkers() as $worker) {
            $this->observe($worker);
        }

        // The journal is replayed for attribution on a short interval, not
        // every tick: a benchmark with thousands of jobs would otherwise
        // decode the whole log on every fast pass.
        $rows = $this->rows();

        foreach ($this->inFlight as $jobId => $workerId) {
            $row = $rows[$jobId] ?? null;

            if ($row === null) {
                continue;
            }

            switch (JobState::fromName((string) $row['state'])) {
                case JobState::COMPLETED:
                    $this->workers[$workerId]['tasks_completed']++;
                    $this->resolvedAt[$jobId] = $this->clock->now();
                    $this->resolvedCount++;
                    unset($this->inFlight[$jobId]);
                    break;
                case JobState::FAILED:
                    $this->workers[$workerId]['tasks_failed']++;
                    $this->resolvedCount++;
                    unset($this->inFlight[$jobId]);
                    break;
                default:
                    // READY/PROCESSING/DELAYED - the job is still someone's
                    // work (a retry waiting, or a delivery in flight).
                    break;
            }
        }
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
     * resolved yet - how a benchmark measures per-job latency without polling
     * the journal (the registry credits it the moment the answer lands).
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
     * How many workers are holding a job right now.
     */
    private function busyCount(): int
    {
        $busy = 0;

        foreach ($this->pool->getWorkers() as $worker) {
            if ($worker->isWorking()) {
                $busy++;
            }
        }

        return $busy;
    }

    /**
     * The journal replay, cached for JOURNAL_REFRESH_SECONDS.
     *
     * @return array<string, array<string, mixed>>
     */
    private function rows(): array
    {
        $now = $this->clock->now();

        if ($this->rowsCache === null || $now - $this->rowsCacheAt >= self::JOURNAL_REFRESH_SECONDS) {
            $this->rowsCache = $this->journal->rows();
            $this->rowsCacheAt = $now;
        }

        return $this->rowsCache;
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

    /**
     * Keep one row per worker id current: pid, state and current job. A pid
     * change means the worker process was replaced (php-job-queue reuses the
     * id for a fresh worker), so the counter resets and the clock restarts.
     */
    private function observe(Worker $worker): void
    {
        $id = $worker->getId();
        $pid = $worker->getPid();

        if (!isset($this->workers[$id])) {
            $this->workers[$id] = [
                'pid' => $pid,
                'state' => $worker->getState()->name,
                'current_job' => $this->currentJobId($worker),
                'started_at' => $this->clock->now(),
                'tasks_completed' => 0,
                'tasks_failed' => 0,
            ];

            return;
        }

        if ($pid !== $this->workers[$id]['pid']) {
            $this->workers[$id]['pid'] = $pid;
            $this->workers[$id]['started_at'] = $this->clock->now();
            $this->workers[$id]['tasks_completed'] = 0;
            $this->workers[$id]['tasks_failed'] = 0;
        }

        $this->workers[$id]['state'] = $worker->getState()->name;
        $this->workers[$id]['current_job'] = $this->currentJobId($worker);
    }

    private function currentJobId(Worker $worker): ?string
    {
        $job = $worker->getCurrentJob();

        return $job?->getId()->toString();
    }
}
