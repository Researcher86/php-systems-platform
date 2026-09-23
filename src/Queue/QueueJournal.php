<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Queue;

use PhpJobQueue\Job\JobState;
use PhpJobQueue\Persistence\FileStorage;

/**
 * The platform's read-side view of the queue, straight off the append-only
 * journal.
 *
 * Both `queue:status` and `GET /queue/status` read the same durable file a
 * producer writes and a consumer rewrites, never an in-memory counter: the
 * journal is the observable truth of the queue, shared by however many
 * processes are alive. Restoring it is just replaying the last word written
 * about every job (FileStorage::load()); counting the states in that replay
 * is what this class turns into the metrics PLAN.md Step 9 names - depth of
 * work that still needs a worker, and how much of the traffic has been
 * published, completed, failed and retried.
 */
final readonly class QueueJournal
{
    public function __construct(
        private string $logPath,
    ) {
    }

    public function logPath(): string
    {
        return $this->logPath;
    }

    /**
     * The journal replay of every job's last known state, keyed by job id.
     *
     * @return array<string, array<string, mixed>>
     */
    public function rows(): array
    {
        return new FileStorage($this->logPath)->load();
    }

    /**
     * The counters in the vocabulary PLAN.md Step 9 attaches to queue
     * metrics. `depth` counts every job that is still someone's work, in
     * whatever flight stage (CREATED/DELAYED/READY/PROCESSING), while
     * `published` counts every job that ever entered the queue - so
     * published = completed + failed + depth, once everything settles.
     * `retried` counts jobs that needed more than one delivery.
     *
     * @return array{depth: int, published: int, completed: int, failed: int, retried: int}
     */
    public function snapshot(): array
    {
        $depth = 0;
        $published = 0;
        $completed = 0;
        $failed = 0;
        $retried = 0;

        foreach ($this->rows() as $data) {
            $published++;

            switch (JobState::fromName((string) $data['state'])) {
                case JobState::COMPLETED:
                    $completed++;
                    break;
                case JobState::FAILED:
                    $failed++;
                    break;
                default:
                    $depth++;
                    break;
            }

            if ((int) ($data['attempts'] ?? 0) > 1) {
                $retried++;
            }
        }

        return [
            'depth' => $depth,
            'published' => $published,
            'completed' => $completed,
            'failed' => $failed,
            'retried' => $retried,
        ];
    }
}
