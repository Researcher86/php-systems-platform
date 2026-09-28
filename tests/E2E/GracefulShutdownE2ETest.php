<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Tests\E2E;

use PhpSystemsPlatform\Queue\Jobs\DemoSlowJob;
use PhpSystemsPlatform\Tests\Support\PlatformTestStack;
use PHPUnit\Framework\TestCase;

/**
 * PLAN Step 28's graceful-shutdown scenario, end to end, from the outside.
 *
 * Every other shutdown claim in this platform is made by a process about
 * itself: it says it stopped accepting work, finished what it was running and
 * released what it owned. This suite is the one place where that claim is
 * tested the way an operator meets it - a SIGTERM sent to a real process,
 * and the evidence read from the two places that cannot be faked: the exit
 * code, and the append-only journal another process owns.
 *
 * The shape of the test is the one that makes the claim falsifiable. A
 * consumer is SIGTERMed while it is demonstrably holding a job that takes
 * three seconds, so the two ways to fail are both visible: exiting at once
 * abandons the job, and never exiting hangs the platform. Finishing the job
 * before the exit, and exiting zero, is the whole contract.
 *
 * The serve is the harness's own, on the standard port: a platform that
 * survives its own shutdown has to be stopped the way it is started, over
 * HTTP, by the request that asks for it.
 */
final class GracefulShutdownE2ETest extends TestCase
{
    private const SLOW_JOB_SECONDS = 3.0;

    private static PlatformTestStack $stack;

    public static function setUpBeforeClass(): void
    {
        self::$stack = PlatformTestStack::startOwn();

        // The queue is drained to empty first: a job that has to be the only
        // pending one is a job this scenario owns, and the journal is shared
        // with every suite that ran before it.
        [$pid, $process] = self::$stack->startConsumer('e2e-shutdown-drain');

        try {
            self::$stack->waitFor(
                static fn (): bool => (int) self::$stack->journal()->snapshot()['depth'] === 0,
                30.0,
                'The queue never drained to empty before the shutdown scenario.',
            );
        } finally {
            self::$stack->stopChild($pid, $process);
        }
    }

    public static function tearDownAfterClass(): void
    {
        $exit = self::$stack->shutdown();

        self::assertSame(0, $exit, 'The scenario\'s own serve did not shut down cleanly on SIGTERM.');

        // The port is closed, so nothing is left listening that pretends to be
        // the platform. The worker pool's socket is deliberately not asserted
        // here: this serve reused the pool the shared stack's serve owns, and
        // reusing someone else's pool means not shutting it down - which is
        // the ownership rule Step 35 documents, not a leak.
        self::assertTrue(self::portIsClosed(), 'Something is still listening on the platform port after shutdown.');
    }

    public function testAConsumerHoldingAJobFinishesItBeforeExitingOnSigterm(): void
    {
        [$consumerPid, $consumerProcess] = self::$stack->startConsumer('e2e-shutdown');

        // Published the way serve's own producer publishes: the same
        // component Producer appending the same journal the consumer restores
        // from. The consumer below is a different process, so this is a real
        // cross-process publish, not a producer and a consumer sharing memory.
        $jobId = self::$stack->publishJob(DemoSlowJob::TYPE, ['seconds' => self::SLOW_JOB_SECONDS]);

        // Caught in flight, not merely published: the signal below lands while
        // a worker is busy on this job, which is the only state in which a
        // graceful shutdown has anything to be graceful about.
        self::$stack->waitFor(
            fn (): bool => $this->state($jobId) === 'PROCESSING',
            15.0,
            'The consumer never started the job.',
        );

        $stoppedAt = microtime(true);
        $exit = self::$stack->stopChild($consumerPid, $consumerProcess, 20.0);
        $shutdownSeconds = microtime(true) - $stoppedAt;

        // It waited for the job it was carrying, and only then exited - zero
        // is not enough on its own, a stop that returns zero immediately is
        // what an abandoned job looks like from the outside.
        self::assertSame(0, $exit, 'The consumer did not exit zero on SIGTERM.');
        self::assertGreaterThan(
            1.0,
            $shutdownSeconds,
            'The consumer exited before the job it was carrying had finished.',
        );

        // The job survived the process that was carrying it: durable publish,
        // a journal another process owns, and a shutdown that finished what it
        // had taken rather than dropping it.
        $row = $this->row($jobId);

        self::assertSame('COMPLETED', $row['state'] ?? '', 'The in-flight job was abandoned on shutdown.');
        self::assertSame(1, (int) ($row['attempts'] ?? 0), 'A job that was never lost should not be retried.');
        self::assertSame(0, (int) self::$stack->journal()->snapshot()['depth'], 'The queue did not end up empty.');

        // The process is really gone, and it said why on its way out: the
        // shutdown sequence the consumer prints is the one that waits for
        // running work, so its presence is the difference between a graceful
        // stop and a kill that happened to return zero.
        self::assertFalse(posix_kill($consumerPid, 0), 'The consumer is still alive after it exited.');

        $log = self::$stack->log('e2e-shutdown');

        self::assertStringContainsString('Consumer stopped.', $log);
        self::assertStringContainsString('finish executing', $log);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $jobId): array
    {
        return self::$stack->journal()->rows()[$jobId] ?? [];
    }

    private function state(string $jobId): string
    {
        return (string) ($this->row($jobId)['state'] ?? '');
    }

    private static function portIsClosed(): bool
    {
        $socket = @stream_socket_client('tcp://127.0.0.1:' . self::$stack->httpPort(), $code, $message, 1.0);

        if ($socket !== false) {
            fclose($socket);
        }

        return $socket === false;
    }
}
