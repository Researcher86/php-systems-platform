<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Tests\Unit;

use PhpSystemsPlatform\Workers\WorkerMemoryTasks;
use PHPUnit\Framework\TestCase;
use PhpWorkerPool\Protocol\Request;

/**
 * The pool-side half of PLAN Step 16. `memory.hold` runs for real here (no
 * fork needed - it only touches this process's own memory), so the actual
 * allocation and growth are covered directly; ServeIntegrationTest covers
 * the real pool wiring end to end.
 */
final class WorkerMemoryTasksTest extends TestCase
{
    public function testUnknownActionIsAnError(): void
    {
        $response = new WorkerMemoryTasks()->handler()(new Request('memory.unknown', []));

        self::assertFalse($response->successful);
        self::assertSame('unknown_action', $response->payload['error']);
    }

    public function testAnOutOfRangeElementCountIsRejected(): void
    {
        $response = new WorkerMemoryTasks()->handler()(new Request('memory.hold', ['elements' => 10_000_000]));

        self::assertFalse($response->successful);
        self::assertSame('bad_params', $response->payload['error']);
    }

    public function testHoldingMemoryReportsThisProcessAndGrowsItsPrivateMemory(): void
    {
        $handler = new WorkerMemoryTasks()->handler();

        $response = $handler(new Request('memory.hold', ['elements' => 200_000]));

        self::assertTrue($response->successful);
        self::assertSame(getmypid(), $response->payload['pid']);
        self::assertSame(
            ['phpUsage', 'phpRealUsage', 'rss', 'privateMemory', 'sharedMemory'],
            array_keys($response->payload['before']),
        );

        // Holding the array is real growth, visible on the very next
        // snapshot within the same call.
        self::assertGreaterThan($response->payload['before']['phpUsage'], $response->payload['after']['phpUsage']);
    }

    public function testEachCallAddsToWhatThisWorkerAlreadyHolds(): void
    {
        // A real pool worker answers memory.hold more than once over its
        // life; what it holds from an earlier call must still be there, not
        // replaced - the whole point is a long-lived process accumulating.
        $tasks = new WorkerMemoryTasks();
        $handler = $tasks->handler();

        $first = $handler(new Request('memory.hold', ['elements' => 200_000]));
        $second = $handler(new Request('memory.hold', ['elements' => 200_000]));

        self::assertGreaterThan($first->payload['after']['phpUsage'], $second->payload['after']['phpUsage']);
        self::assertSame(1, $first->payload['holds']);
        self::assertSame(2, $second->payload['holds']);
    }
}
