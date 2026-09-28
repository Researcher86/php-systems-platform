<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * `experiments` reproduces PLAN Step 30's five failure/overload scenarios
 * end to end: it owns a serve and a queue consumer, runs each experiment
 * against the platform it started, prints the observation, and stops
 * everything on every path. The assertions watch the reproducible facts, not
 * the timing-sensitive ones: five scenarios ran, each made its claim
 * (a backlog drained, a worker was replaced, a dead cache became a miss, a
 * slow database cost the request its 300ms, a full queue answered 429 and
 * the same call was accepted once it drained).
 */
final class FailureExperimentsCommandTest extends TestCase
{
    public function testRunsAllFiveExperimentsAndExitsZero(): void
    {
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/bin/platform.php', 'experiments'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        self::assertIsResource($process);

        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        self::assertSame(0, $code, $errors . $output);

        // Experiment 1 - slow workers: the backlog built up and drained.
        self::assertStringContainsString('1. Slow workers', $output);
        self::assertStringContainsString('drained', $output);

        // Experiment 2 - a crashed worker was replaced.
        self::assertStringContainsString('2. A crashed worker', $output);
        self::assertStringContainsString('replacement pid', $output);
        self::assertStringContainsString('detected the dead worker', $output);

        // Experiment 3 - a dead cache is a miss, not a failure.
        self::assertStringContainsString('3. Cache down', $output);
        self::assertStringContainsString('X-Cache: miss', $output);
        self::assertStringContainsString('fall through to the database', $output);

        // Experiment 4 - the slow database cost the request its delay.
        self::assertStringContainsString('4. A slow database', $output);
        self::assertStringContainsString('paid the configured 300 ms database delay', $output);

        // Experiment 5 - full queue rejected, then accepted once drained.
        self::assertStringContainsString('5. A full queue', $output);
        self::assertStringContainsString('HTTP 429', $output);
        self::assertStringContainsString('HTTP 201', $output);
        self::assertStringContainsString('nothing is enqueued', $output);
    }
}
