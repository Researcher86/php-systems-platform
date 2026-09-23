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
     * workerId => job id it was last seen busy on, awaiting a terminal state.
     *
     * @var array<int, string>
     */
    private array $inFlight = [];

    private float $nextSnapshotAt = 0.0;

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
        foreach ($this->pool->getWorkers() as $worker) {
            $this->observe($worker);

            if (!$worker->isWorking()) {
                continue;
            }

            $job = $worker->getCurrentJob();

            if ($job !== null) {
                $this->inFlight[$worker->getId()] = $job->getId()->toString();
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

        foreach ($this->inFlight as $workerId => $jobId) {
            $row = $this->journal->rows()[$jobId] ?? null;

            if ($row === null) {
                continue;
            }

            switch (JobState::fromName((string) $row['state'])) {
                case JobState::COMPLETED:
                    $this->workers[$workerId]['tasks_completed']++;
                    unset($this->inFlight[$workerId]);
                    break;
                case JobState::FAILED:
                    $this->workers[$workerId]['tasks_failed']++;
                    unset($this->inFlight[$workerId]);
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
