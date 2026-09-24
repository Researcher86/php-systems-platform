<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Tests\Unit\Memory;

use PhpSystemsPlatform\Memory\ForkedMemoryDemo;
use PHPUnit\Framework\TestCase;

/**
 * PLAN Step 15's whole point, made observable: a forked child starts out
 * sharing its parent's pages and only accumulates private ones once it
 * writes. Both snapshots come from the real container's /proc, so the
 * assertions are relative (before/after) rather than pinned to a byte count
 * that would drift with the kernel or the allocator.
 */
final class ForkedMemoryDemoTest extends TestCase
{
    public function testTheChildEndsUpWithMorePrivateMemoryThanItStartedWith(): void
    {
        $result = new ForkedMemoryDemo(elements: 2_000_000)->run();

        self::assertNotNull($result->afterFork->privateMemory);
        self::assertNotNull($result->afterModification->privateMemory);

        // Writing to the shared array forces the kernel to copy the pages
        // the write touches - the child's own private memory grows.
        self::assertGreaterThan($result->afterFork->privateMemory, $result->afterModification->privateMemory);
    }

    public function testForkingItselfCostsFarLessThanTheArrayItShares(): void
    {
        $result = new ForkedMemoryDemo(elements: 2_000_000)->run();

        self::assertNotNull($result->beforeFork->rss);
        self::assertNotNull($result->afterFork->rss);

        // Right after fork the child's RSS is close to its parent's - it is
        // the same pages, not a copy of them. "Close" here means nowhere
        // near what re-allocating the array from scratch would cost.
        $shared = abs($result->afterFork->rss - $result->beforeFork->rss);
        self::assertLessThan($result->beforeFork->rss / 4, $shared);
    }

    public function testNoChildIsLeftUnreaped(): void
    {
        new ForkedMemoryDemo(elements: 1_000)->run();

        self::assertLessThanOrEqual(0, pcntl_waitpid(-1, $status, WNOHANG));
    }
}
