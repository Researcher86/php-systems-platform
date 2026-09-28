<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Tests\E2E;

use PhpSystemsPlatform\Queue\Jobs\DemoSlowJob;
use PhpSystemsPlatform\Queue\Jobs\NoopJob;
use PhpSystemsPlatform\Tests\Support\PlatformTestStack;
use PHPUnit\Framework\TestCase;

/**
 * PLAN Step 28's worker-crash and retry scenarios, end to end.
 *
 * A crash and a retry are the two things this platform promises to survive,
 * and neither can be observed from inside the process it happens to: the
 * worker that dies is a child of the pool's Master, the job it was carrying
 * is a row in a journal another process owns, and the recovery is the two of
 * them talking again. So both scenarios here are staged from the outside,
 * against a real consumer and a real pool:
 *
 *   Scenario 4 - a worker dies on demand (the platform's own
 *     POST /debug/fail-worker) and the pool replaces it, while a batch of
 *     real jobs is flowing through it. Every job that batch carried still
 *     arrives: the crash costs a worker, not work.
 *
 *   Scenario 5 - a worker is killed from the outside with SIGKILL, holding a
 *     job that takes long enough to be caught holding it. The job's attempt
 *     dies with the worker, the queue's retry policy delivers it again, and
 *     it completes on the replacement - the platform's own answer to
 *     at-least-once delivery.
 *
 * The job under test in scenario 5 is demo.slow, because "kill the worker
 * that is holding this job" is only a question that can be asked of a job
 * that is still running. Millisecond work would turn both scenarios into a
 * race against a scheduler, and a test that wins a race proves nothing.
 */
final class WorkerCrashE2ETest extends TestCase
{
    private const BATCH = 8;

    /**
     * bin/worker.php launches the pool with WORKER_POOL_MIN=2 and a maximum of
     * 16, autoscale in between: the pool is sized for the work it is handed,
     * so its worker count is a moving target and only its floor is a promise.
     */
    private const MIN_POOL_WORKERS = 2;

    private static PlatformTestStack $stack;

    public static function setUpBeforeClass(): void
    {
        self::$stack = PlatformTestStack::acquire();
    }

    public static function tearDownAfterClass(): void
    {
        PlatformTestStack::release();
    }

    /**
     * Scenario 4 - worker crash: worker failure, worker replacement, and a
     * queue that keeps delivering while the pool is one worker short.
     */
    public function testACrashedWorkerIsReplacedAndNoJobIsLostWhileItHappens(): void
    {
        $this->withConsumer(function (): void {
            $pidsBefore = $this->poolPids();
            $batch = [];

            for ($i = 0; $i < self::BATCH; $i++) {
                $batch[] = self::$stack->publishJob(NoopJob::TYPE, ['crash_scenario' => $i]);
            }

            // The platform's own failure injection: one call that SIGKILLs
            // whichever worker takes the task, and reports each phase of what
            // the pool's bookkeeping made of it.
            [$status, $answer] = self::$stack->http('POST', '/debug/fail-worker');

            self::assertSame(200, $status);

            $report = json_decode($answer, true, 512, JSON_THROW_ON_ERROR);

            // Worker failure and worker replacement, as the pool's own
            // bookkeeping recorded them at the moment of the crash: the
            // worker that took the task never answered it, the dead pid left
            // the pool's list, and the Master forked a replacement.
            self::assertTrue($report['crash_detected'], 'The worker did not die on the crash task.');
            self::assertNotNull($report['crashed_pid']);
            self::assertTrue($report['worker_removed'], 'The pool still lists the dead worker.');
            self::assertTrue($report['replacement_started'], 'The pool started no replacement.');

            // What the pool looks like afterwards is read as an invariant and
            // not as an identity: the pool autoscales between its minimum and
            // maximum, so the pid that replaced the dead one may itself have
            // been recycled by the time this reads. What has to hold is that
            // the dead pid is gone, the pool is serving, and the worker that
            // took its place is a pid that was not there before the crash.
            $pidsAfter = $this->poolPids();

            self::assertNotContains((int) $report['crashed_pid'], $pidsAfter);
            self::assertNotEmpty(array_diff($pidsAfter, $pidsBefore), 'The pool is running the same workers it was before the crash.');
            self::assertGreaterThanOrEqual(
                self::MIN_POOL_WORKERS,
                count($pidsAfter),
                'The pool is serving with fewer workers than its configured minimum.',
            );

            // Job recovery: the crash cost a worker, not work. Every job of
            // the batch is terminal and none of them is stuck in flight -
            // including any whose attempt the crash took, which the retry
            // policy delivered again.
            self::$stack->waitFor(
                fn (): bool => $this->allSettled($batch),
                30.0,
                'The queue did not finish the batch published across the crash.',
            );

            $rows = self::$stack->journal()->rows();

            foreach ($batch as $id) {
                self::assertSame(
                    'COMPLETED',
                    (string) ($rows[$id]['state'] ?? ''),
                    sprintf('Job %s was not completed after the crash.', $id),
                );
            }
        });
    }

    /**
     * Scenario 5 - retry: a job whose worker is SIGKILLed from the outside
     * fails, is retried, and succeeds on the pool's replacement worker.
     */
    public function testAJobKilledWithItsWorkerIsRetriedAndCompletesOnTheReplacement(): void
    {
        $this->withConsumer(function (): void {
            $retriedBefore = (int) self::$stack->journal()->snapshot()['retried'];
            $jobId = self::$stack->publishJob(DemoSlowJob::TYPE, ['seconds' => 2.0]);

            // Wait until the job is genuinely in a worker's hands: the journal
            // says PROCESSING and the pool says which worker is BUSY. The
            // kill below is aimed at that pid, not at a guess.
            $victim = 0;

            self::$stack->waitFor(function () use ($jobId, &$victim): bool {
                if ((string) ($this->jobById($jobId)['state'] ?? '') !== 'PROCESSING') {
                    return false;
                }

                foreach (self::$stack->pool()->stats() as $row) {
                    if (($row['state'] ?? '') === 'BUSY') {
                        $victim = (int) $row['pid'];

                        return true;
                    }
                }

                return false;
            }, 15.0, 'No pool worker was caught holding the job.');

            // The OOM-kill, from outside the platform: the same signal a
            // kernel sends, to the pid the pool itself reported as busy.
            self::assertTrue(posix_kill($victim, SIGKILL), 'Could not kill the pool worker holding the job.');

            // The job the dead worker was carrying is delivered again and
            // finishes on a worker that is not the one that died.
            self::$stack->waitFor(
                fn (): bool => (string) ($this->jobById($jobId)['state'] ?? '') === 'COMPLETED',
                30.0,
                'The killed job was never recovered and completed.',
            );

            $job = $this->jobById($jobId);

            self::assertSame(2, (int) $job['attempts'], 'The job should have spent two attempts.');
            self::assertStringContainsString(
                'worker_crashed',
                (string) ($job['lastError'] ?? ''),
                'The first attempt did not fail the way a dead worker fails.',
            );
            self::assertGreaterThan($retriedBefore, (int) self::$stack->journal()->snapshot()['retried']);

            // The completing attempt ran on a live worker: the pool is still
            // serving, and the pid that was killed is not one of them.
            $pids = $this->poolPids();

            self::assertNotContains($victim, $pids, 'The pool still lists the worker that was killed.');
            self::assertGreaterThanOrEqual(
                self::MIN_POOL_WORKERS,
                count($pids),
                'The pool is not serving the work it took back.',
            );
        });
    }

    /**
     * @param callable(): void $body
     */
    private function withConsumer(callable $body): void
    {
        [$pid, $process] = self::$stack->startConsumer('e2e-crash');

        try {
            $body();
        } finally {
            $exit = self::$stack->stopChild($pid, $process);

            self::assertSame(0, $exit, 'The queue consumer did not shut down cleanly on SIGTERM.');
        }
    }

    /**
     * @param list<string> $jobIds
     */
    private function allSettled(array $jobIds): bool
    {
        $rows = self::$stack->journal()->rows();

        foreach ($jobIds as $id) {
            if (!in_array((string) ($rows[$id]['state'] ?? ''), ['COMPLETED', 'FAILED'], true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<int>
     */
    private function poolPids(): array
    {
        return array_map(
            static fn (array $row): int => (int) $row['pid'],
            self::$stack->pool()->stats(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function jobById(string $jobId): array
    {
        return self::$stack->journal()->rows()[$jobId] ?? [];
    }
}
