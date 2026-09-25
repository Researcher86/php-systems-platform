<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * PLAN Step 26, end to end: the `demo` command runs the whole platform as
 * one story - its own serve and queue:consume on the real ports, 100 real
 * orders, the journal, a crashed and replaced worker, a retried-then-failed
 * job, the live /metrics, and a graceful shutdown - and must tell that
 * story and exit 0. The demo owns the ports for its duration and refuses to
 * start against a platform that is already answering, so this test skips
 * when another serve is up rather than fighting it for the sockets.
 */
final class DemoCommandTest extends TestCase
{
    private const HOST = '127.0.0.1';
    private const HTTP_PORT = 8080;

    private const DEMO_DEADLINE_SECONDS = 180.0;

    public function testDemoRunsTheWholePlatformStoryAndExitsZero(): void
    {
        if (self::portAnswers(self::HTTP_PORT, 1.0)) {
            self::markTestSkipped('A platform serve is already running; the demo would refuse to share its ports.');
        }

        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/bin/platform.php', 'demo'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        self::assertIsResource($process);

        $pid = (int) proc_get_status($process)['pid'];
        $startedAt = microtime(true);
        $reaped = null;

        try {
            do {
                $reaped = pcntl_waitpid($pid, $waitStatus, WNOHANG);
                usleep(100_000);
            } while ($reaped !== $pid && microtime(true) - $startedAt < self::DEMO_DEADLINE_SECONDS);

            if ($reaped !== $pid) {
                proc_terminate($process, 9);
                $reaped = pcntl_waitpid($pid, $waitStatus);
            }

            $exit = $reaped === $pid ? pcntl_wexitstatus($waitStatus) : -1;
            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);

            self::assertSame(0, $exit, sprintf(
                'The demo exited %d after %.1fs. stderr:%s%sstdout:%s%s',
                $exit,
                microtime(true) - $startedAt,
                PHP_EOL,
                $errors,
                PHP_EOL,
                $output,
            ));

            self::assertSame('', (string) $errors, 'The demo wrote to stderr.');

            $output = (string) $output;

            // The whole PLAN Step 26 story, in order.
            foreach ([
                'Starting platform...',
                'HTTP server............ OK',
                'Workers................ 4',
                'Creating orders...',
                'Created 100 orders',
                'Publishing jobs...',
                'Published 100 jobs',
                'Processing...',
                'Injecting failure...',
                'exited unexpectedly',
                'Recovering...',
                'restarted',
                'Retrying failed jobs...',
                'attempt 3 -> failed',
                'Final statistics...',
                'Queue     ',
                'Graceful shutdown...',
                'stopped gracefully',
                'All services stopped.',
            ] as $line) {
                self::assertStringContainsString($line, $output, 'Missing story line: ' . $line);
            }

            // Every worker was credited: the per-worker lines add up to the
            // hundred jobs the batch held.
            preg_match_all('/Worker #\d+ processed (\d+) jobs/', $output, $perWorker);
            self::assertCount(4, $perWorker[1], 'Expected one processed line per worker.');
            self::assertSame(100, array_sum(array_map('intval', $perWorker[1])), $output);

            // The final /metrics snapshot reported the numbers the story ran:
            // one hundred completed, one failed beyond its attempts budget.
            self::assertMatchesRegularExpression('/^  Queue\s+\d+ published, 100 completed, 1 failed, 1 retried$/m', $output);
        } finally {
            if (is_resource($process)) {
                proc_terminate($process, 9);
                proc_close($process);
            }
        }
    }

    private static function portAnswers(int $port, float $timeoutSeconds): bool
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            $socket = @stream_socket_client(
                sprintf('tcp://%s:%d', self::HOST, $port),
                $errorCode,
                $errorMessage,
                0.2,
            );

            if ($socket !== false) {
                fclose($socket);

                return true;
            }

            usleep(100_000);
        }

        return false;
    }
}
