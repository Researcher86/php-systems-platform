<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * `workers:memory` needs no platform infrastructure beyond the worker pool
 * it spawns for itself - no database, cache or queue - so it runs as its
 * own standalone subprocess, the same way MemoryDemoCommandTest does for
 * `memory:demo`.
 */
final class WorkersMemoryCommandTest extends TestCase
{
    public function testRunsAllFourWorkerCountsAndShowsTotalMemoryGrowingWithThem(): void
    {
        $process = proc_open(
            // A small per-worker allocation keeps four pool spin-ups fast
            // without changing what the comparison demonstrates.
            [PHP_BINARY, dirname(__DIR__, 2) . '/bin/platform.php', 'workers:memory', '100000'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        self::assertIsResource($process);

        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        self::assertSame(0, $code, $errors);

        foreach ([1, 2, 4, 8] as $workers) {
            // Each count is a row's leftmost column, not adjacent text.
            self::assertMatchesRegularExpression(sprintf('/^\s*%d\s+\d/m', $workers), $output);
        }

        self::assertStringContainsString('total before', $output);
        self::assertStringContainsString('total after', $output);

        // One row per worker count, each carrying a total-after-mutation
        // figure - parsed out to check the actual claim the demo makes.
        preg_match_all('/^\s*(?:1|2|4|8)\s+[\d.]+M\s+[\d.]+M\s+[\d.]+M\s+[\d.]+M\s+([\d.]+)M\s*$/m', $output, $totals);
        self::assertCount(4, $totals[1]);

        // 8 workers each holding their own array costs more, in total, than
        // 1 worker holding the same array - that is the whole point.
        self::assertGreaterThan((float) $totals[1][0], (float) $totals[1][3]);
    }
}
