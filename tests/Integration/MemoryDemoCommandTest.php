<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * `memory:demo` needs none of the platform's infrastructure - no database,
 * cache, queue or worker pool - so unlike ServeIntegrationTest this runs the
 * real CLI command as its own subprocess without a `serve` behind it.
 */
final class MemoryDemoCommandTest extends TestCase
{
    public function testPrintsAllThreeSnapshotsAndDemonstratesGrowingPrivateMemory(): void
    {
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/bin/platform.php', 'memory:demo'],
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
        self::assertStringContainsString('before fork', $output);
        self::assertStringContainsString('after fork', $output);
        self::assertStringContainsString('after modification', $output);

        // The child's private memory grows once it writes - the one number
        // the demo exists to show, so it must appear as an actual increase.
        self::assertMatchesRegularExpression('/private memory.*after modification.*\+[1-9]/is', $output);
    }
}
