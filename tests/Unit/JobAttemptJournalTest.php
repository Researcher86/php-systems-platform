<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Tests\Unit;

use PhpSystemsPlatform\Queue\JobAttemptJournal;
use PHPUnit\Framework\TestCase;

/**
 * The job metadata PLAN Step 19 asks for and the queue component does not
 * track at all - `PhpJobQueue\Job\Job::toArray()` has no started_at,
 * completed_at or last_error field (verified by reading it, not assumed).
 * This is the platform's own append-only record of it, one row per
 * execution attempt.
 */
final class JobAttemptJournalTest extends TestCase
{
    private string $logPath;

    protected function setUp(): void
    {
        $this->logPath = tempnam(sys_get_temp_dir(), 'attempt-journal-test-');
        unlink($this->logPath);
    }

    protected function tearDown(): void
    {
        @unlink($this->logPath);
    }

    public function testANeverRecordedJobHasNoRows(): void
    {
        self::assertSame([], new JobAttemptJournal($this->logPath)->forJob('missing'));
    }

    public function testRecordsOneRowPerAttemptWithItsOwnTiming(): void
    {
        $journal = new JobAttemptJournal($this->logPath);

        $journal->record('job-1', attempt: 1, startedAt: 100.0, completedAt: 100.5, error: 'db unreachable');
        $journal->record('job-1', attempt: 2, startedAt: 105.0, completedAt: 105.2, error: null);

        $rows = $journal->forJob('job-1');

        self::assertCount(2, $rows);
        self::assertSame(1, $rows[0]['attempt']);
        self::assertSame(100.0, $rows[0]['started_at']);
        self::assertSame(100.5, $rows[0]['completed_at']);
        self::assertSame('db unreachable', $rows[0]['last_error']);
        self::assertSame(2, $rows[1]['attempt']);
        self::assertNull($rows[1]['last_error']);
    }

    public function testDifferentJobsDoNotLeakIntoEachOthersHistory(): void
    {
        $journal = new JobAttemptJournal($this->logPath);
        $journal->record('job-1', 1, 1.0, 1.1, null);
        $journal->record('job-2', 1, 2.0, 2.1, null);

        self::assertCount(1, $journal->forJob('job-1'));
        self::assertCount(1, $journal->forJob('job-2'));
    }
}
