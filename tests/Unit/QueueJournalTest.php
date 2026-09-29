<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Tests\Unit;

use PhpJobQueue\Persistence\FileStorage;
use PhpSystemsPlatform\Queue\QueueJournal;
use PHPUnit\Framework\TestCase;

/**
 * The journal-derived counters behind `queue:status` and `GET /queue/status`,
 * read straight off the append-only log the producer writes and the
 * consumer rewrites.
 */
final class QueueJournalTest extends TestCase
{
    private string $dir;
    private string $logPath;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/php-systems-platform-queue-journal-' . uniqid('', true);
        mkdir($this->dir, 0o777, true);
        $this->logPath = $this->dir . '/queue.log';
    }

    protected function tearDown(): void
    {
        if (is_file($this->logPath)) {
            unlink($this->logPath);
        }

        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    public function testSnapshotCountsStatesAndRetriesFromTheJournal(): void
    {
        $storage = new FileStorage($this->logPath);

        $storage->store('job-ready', $this->row('READY', 0));
        $storage->store('job-retried', $this->row('READY', 2));
        $storage->store('job-done', $this->row('COMPLETED', 1));
        $storage->store('job-dead', $this->row('FAILED', 3));

        $snapshot = new QueueJournal($this->logPath)->snapshot();

        // Two jobs are still someone's work, the other two are terminal.
        self::assertSame(2, $snapshot['depth']);
        self::assertSame(4, $snapshot['published']);
        self::assertSame(1, $snapshot['completed']);
        self::assertSame(1, $snapshot['failed']);
        // The jobs that needed more than one delivery: the retried-and-ready
        // one and the one that failed after three attempts.
        self::assertSame(2, $snapshot['retried']);
    }

    public function testSnapshotIsAllZerosForAMissingJournal(): void
    {
        $snapshot = new QueueJournal($this->logPath)->snapshot();

        self::assertSame(
            ['depth' => 0, 'published' => 0, 'completed' => 0, 'failed' => 0, 'retried' => 0],
            $snapshot,
        );
    }

    /**
     * The cache must never make a caller read a stale queue. A producer
     * appending between two reads is the ordinary case - the HTTP server's
     * backpressure policy and its status route share one journal - so the
     * second snapshot has to see the row the first one could not.
     */
    public function testAnAppendIsVisibleToTheNextSnapshot(): void
    {
        $storage = new FileStorage($this->logPath);
        $journal = new QueueJournal($this->logPath);

        $storage->store('job-1', $this->row('READY', 0));
        self::assertSame(1, $journal->snapshot()['depth']);

        // A second read with nothing appended is the cached one.
        self::assertSame(1, $journal->snapshot()['depth']);

        $storage->store('job-2', $this->row('READY', 0));
        self::assertSame(2, $journal->snapshot()['depth']);

        // A state change to an existing job appends a new line, so it is
        // visible for the same reason a new row is.
        $storage->store('job-1', $this->row('COMPLETED', 1));
        $after = $journal->snapshot();
        self::assertSame(1, $after['depth']);
        self::assertSame(1, $after['completed']);
    }

    /**
     * A file that does not exist yet and one that is created must not look
     * alike: "no jobs" and "not created yet" are both zero rows, and a cache
     * keyed on the absent file's stats must still notice the arrival.
     */
    public function testAJournalThatAppearsAfterTheFirstReadIsNoticed(): void
    {
        $journal = new QueueJournal($this->logPath);

        self::assertSame([], $journal->rows());

        new FileStorage($this->logPath)->store('job-1', $this->row('READY', 0));

        self::assertCount(1, $journal->rows());
    }

    /**
     * The same rows, twice, from the same instance: the cache is an
     * optimization, so the answer must not depend on whether it was a hit.
     */
    public function testRepeatedReadsReturnEqualRows(): void
    {
        $storage = new FileStorage($this->logPath);
        $storage->store('job-1', $this->row('READY', 0));
        $storage->store('job-2', $this->row('COMPLETED', 1));

        $journal = new QueueJournal($this->logPath);

        $first = $journal->rows();
        $second = $journal->rows();

        self::assertSame($first, $second);
        self::assertSame(['job-1', 'job-2'], array_keys($first));
    }

    /**
     * The shape FileStorage::store() writes for one job: the component's
     * Job::toArray() payload under 'data'.
     *
     * @return array<string, mixed>
     */
    private function row(string $state, int $attempts): array
    {
        return [
            'id' => 'job-' . uniqid('', true),
            'type' => 'order.created',
            'payload' => [],
            'state' => $state,
            'attempts' => $attempts,
            'maxAttempts' => 3,
            'createdAt' => 100.0,
            'availableAt' => 100.0,
            'priority' => 'NORMAL',
            'idempotencyKey' => null,
        ];
    }
}
