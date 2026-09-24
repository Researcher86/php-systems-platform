<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Tests\Unit;

use PhpSystemsPlatform\Workers\WorkerTasks;
use PHPUnit\Framework\TestCase;
use PhpWorkerPool\Protocol\Request;

/**
 * `sleep` is PLAN Step 18's own task - deliberately the simplest possible
 * one, existing only to hold a worker busy for a controlled duration so a
 * timeout can be demonstrated against it rather than asserted. The actual
 * timeout - a worker held past its execution limit gets killed and replaced
 * - needs a real pool and is covered in ServeIntegrationTest; what belongs
 * here is that the task itself does what it says and rejects what it can't.
 */
final class WorkerTasksTest extends TestCase
{
    public function testSleepsForRoughlyTheRequestedDurationAndReportsIt(): void
    {
        $handler = WorkerTasks::handler();

        $started = microtime(true);
        $response = $handler(new Request('sleep', ['ms' => 20]));
        $elapsed = microtime(true) - $started;

        self::assertTrue($response->successful);
        self::assertGreaterThanOrEqual(0.02, $elapsed);
        self::assertSame(20, $response->payload['ms']);
    }

    public function testAnOutOfRangeDurationIsRejected(): void
    {
        $response = WorkerTasks::handler()(new Request('sleep', ['ms' => 999_999]));

        self::assertFalse($response->successful);
        self::assertSame('bad_params', $response->payload['error']);
    }
}
