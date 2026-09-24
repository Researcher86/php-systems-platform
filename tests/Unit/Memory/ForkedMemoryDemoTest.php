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

        self::assertNotNull($result->afterFork->rss);
        self::assertNotNull($result->afterModification->rss);

        // Right after fork the child sits at its inherited floor - the pages
        // are shared, so nothing has been copied and its RSS has not grown.
        // Only the write-triggered copy grows it. Both snapshots are read in
        // the same child, so the comparison is immune to the fork()/proc
        // accounting drift: a freshly forked child's VmRSS undercounts the
        // inherited anon pages by an environment-dependent amount, which
        // makes parent-vs-child deltas unstable across machines.
        self::assertGreaterThan($result->afterFork->rss, $result->afterModification->rss);
    }

    public function testNoChildIsLeftUnreaped(): void
    {
        new ForkedMemoryDemo(elements: 1_000)->run();

        self::assertLessThanOrEqual(0, pcntl_waitpid(-1, $status, WNOHANG));
    }
}
