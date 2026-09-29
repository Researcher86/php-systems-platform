<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Queue;

use PhpJobQueue\Job\JobState;
use PhpJobQueue\Persistence\FileStorage;

/**
 * The platform's read-side view of the queue, straight off the append-only
 * journal.
 *
 * `queue:status` and `GET /queue/status` read the durable file a producer
 * writes and a consumer rewrites, never an in-memory counter: the journal is
 * the queue's observable truth across every live process. Replaying it
 * (FileStorage::load(), last write per job wins) and counting the states is
 * what yields the PLAN Step 9 metrics.
 *
 * A replay is linear in every row ever written, and backpressure asks for
 * the depth on every order write, so the replay is cached against the file's
 * size and mtime - sound because FileStorage only appends, so an unchanged
 * stat means nothing new was recorded. The file itself still grows without
 * bound; bounding it would be a compaction step the storage component does
 * not have.
 */
final class QueueJournal
{
    /** stat-derived key the cached replay belongs to */
    private ?string $cachedStat = null;

    /** @var array<string, array<string, mixed>> */
    private array $cachedRows = [];

    /** @var array{depth: int, published: int, completed: int, failed: int, retried: int} */
    private array $cachedSnapshot = ['depth' => 0, 'published' => 0, 'completed' => 0, 'failed' => 0, 'retried' => 0];

    public function __construct(
        private readonly string $logPath,
    ) {
    }

    public function logPath(): string
    {
        return $this->logPath;
    }

    /**
     * Every job's last known state, keyed by job id.
     *
     * @return array<string, array<string, mixed>>
     */
    public function rows(): array
    {
        $this->refresh();

        return $this->cachedRows;
    }

    /**
     * `depth` counts every job that is still someone's work (CREATED/DELAYED/
     * READY/PROCESSING) and `published` every job that ever entered the
     * queue, so published = completed + failed + depth. `retried` counts jobs
     * that needed more than one delivery. Cached with the replay, because the
     * count is itself a walk over the whole history.
     *
     * @return array{depth: int, published: int, completed: int, failed: int, retried: int}
     */
    public function snapshot(): array
    {
        $this->refresh();

        return $this->cachedSnapshot;
    }

    /**
     * Re-read the journal only if the file changed - one freshness check
     * shared by rows() and snapshot(), so the two can never disagree.
     */
    private function refresh(): void
    {
        // PHP's stat cache is per process and only cleared by this process's
        // own writes; the consumer appends from another process, so without
        // this a serve that writes nothing (e.g. while answering 503s) would
        // see an unchanged journal forever.
        clearstatcache(true, $this->logPath);
        $stat = @stat($this->logPath);
        $key = $stat === false ? 'missing' : $stat['size'] . ':' . $stat['mtime'];

        if ($key === $this->cachedStat) {
            return;
        }

        $this->cachedRows = new FileStorage($this->logPath)->load();
        $this->cachedSnapshot = self::count($this->cachedRows);
        $this->cachedStat = $key;
    }

    /**
     * @param array<string, array<string, mixed>> $rows
     *
     * @return array{depth: int, published: int, completed: int, failed: int, retried: int}
     */
    private static function count(array $rows): array
    {
        $counts = ['depth' => 0, 'published' => count($rows), 'completed' => 0, 'failed' => 0, 'retried' => 0];

        foreach ($rows as $data) {
            $bucket = match (JobState::fromName((string) $data['state'])) {
                JobState::COMPLETED => 'completed',
                JobState::FAILED => 'failed',
                default => 'depth',
            };
            $counts[$bucket]++;

            if ((int) ($data['attempts'] ?? 0) > 1) {
                $counts['retried']++;
            }
        }

        return $counts;
    }
}
