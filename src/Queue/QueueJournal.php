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
 *
 * That replay used to run once per reader per request: POST /orders asked the
 * backpressure policy for the depth, which replayed the whole journal, and
 * the journal's cost is linear in the number of rows ever written, so a queue
 * that had served a day's traffic charged every new order for a day of
 * history. The replay is therefore cached against the journal's own size and
 * modification time - sound precisely because FileStorage only ever appends
 * (FILE_APPEND), so an unchanged size means no new state was recorded.
 *
 * The cache removes the *repeated* replay, not the growth: the file still
 * grows with every state change, and bounding that is a compaction step in
 * the storage component, which says of itself that a real system would pair
 * the log with periodic snapshots and this one does not.
 */
final class QueueJournal
{
    /** @var array<string, array<string, mixed>>|null */
    private ?array $cachedRows = null;

    /** @var array{depth: int, published: int, completed: int, failed: int, retried: int}|null */
    private ?array $cachedSnapshot = null;

    private int $cachedSize = -1;

    private int $cachedMtime = -1;

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
     * Cached against the file's size and mtime, both of which are stat() -
     * O(1) - so a reader that asks repeatedly within one tick pays once, and
     * any append from any process is visible on the next call.
     *
     * @return array<string, array<string, mixed>>
     */
    public function rows(): array
    {
        return $this->refresh()['rows'];
    }

    /**
     * The counters in the vocabulary PLAN.md Step 9 attaches to queue
     * metrics. `depth` counts every job that is still someone's work, in
     * whatever flight stage (CREATED/DELAYED/READY/PROCESSING), while
     * `published` counts every job that ever entered the queue - so
     * published = completed + failed + depth, once everything settles.
     * `retried` counts jobs that needed more than one delivery.
     *
     * Cached with the replay, not just derived from it: counting is a walk
     * over every row the journal has ever accumulated, and this is on the
     * hot path of every order write (backpressure) and every status read, so
     * a cache that only saved the file read would still charge each request
     * a full walk of the queue's history.
     *
     * @return array{depth: int, published: int, completed: int, failed: int, retried: int}
     */
    public function snapshot(): array
    {
        return $this->refresh()['snapshot'];
    }

    /**
     * Re-read the journal, but only if the file moved under us.
     *
     * One place decides freshness, so rows() and snapshot() can never
     * disagree about it - two independent stat comparisons would be two
     * chances to answer the same question differently.
     *
     * @return array{rows: array<string, array<string, mixed>>, snapshot: array{depth: int, published: int, completed: int, failed: int, retried: int}}
     */
    private function refresh(): array
    {
        $stat = @stat($this->logPath);
        $size = $stat === false ? -1 : $stat['size'];
        $mtime = $stat === false ? -1 : $stat['mtime'];

        if (
            $this->cachedRows !== null
            && $this->cachedSnapshot !== null
            && $size === $this->cachedSize
            && $mtime === $this->cachedMtime
        ) {
            return ['rows' => $this->cachedRows, 'snapshot' => $this->cachedSnapshot];
        }

        $rows = new FileStorage($this->logPath)->load();

        $this->cachedRows = $rows;
        $this->cachedSnapshot = $this->count($rows);
        $this->cachedSize = $size;
        $this->cachedMtime = $mtime;

        return ['rows' => $rows, 'snapshot' => $this->cachedSnapshot];
    }

    /**
     * @param array<string, array<string, mixed>> $rows
     *
     * @return array{depth: int, published: int, completed: int, failed: int, retried: int}
     */
    private function count(array $rows): array
    {
        $depth = 0;
        $published = 0;
        $completed = 0;
        $failed = 0;
        $retried = 0;

        foreach ($rows as $data) {
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
