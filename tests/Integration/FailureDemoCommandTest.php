<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * `failure:demo` reproduces PLAN Step 22's two sequences end to end: it
 * spawns its own throwaway two-worker pool and crashes one of them (Phase 1,
 * printed with each phase's timing), then runs a `demo.failing` job through
 * the real dispatcher until the journal retires it FAILED at max_attempts
 * (Phase 2). Both phases are self-contained - the pool is the command's own,
 * and the failing job runs on a fresh journal - so like memory:demo this runs
 * the real CLI as a subprocess with no serve behind it.
 *
 * It needs the database and cache servers the way serve does, so this test
 * assumes the same dev/test environment ServeIntegrationTest does (the
 * command starts the servers itself if none answer).
 */
final class FailureDemoCommandTest extends TestCase
{
    public function testPrintsBothFailureSequencesAndExitsZero(): void
    {
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/bin/platform.php', 'failure:demo'],
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

        // Phase 1 - the worker crash sequence, and its three observed events.
        self::assertStringContainsString('A worker crashes.', $output);
        self::assertStringContainsString('manager detects', $output);
        self::assertStringContainsString('worker removed', $output);
        self::assertStringContainsString('replacement started', $output);

        // Phase 2 - the failing job dies FAILED at its full attempts budget.
        self::assertStringContainsString('A job keeps failing.', $output);
        self::assertStringContainsString('FAILED (dead state)', $output);
        self::assertStringContainsString('Injected failure (demo.failing).', $output);
    }
}
