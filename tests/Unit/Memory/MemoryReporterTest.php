<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Tests\Unit\Memory;

use PhpSystemsPlatform\Memory\MemoryReporter;
use PHPUnit\Framework\TestCase;

/**
 * MemoryReporter joins the PHP allocator counters with the /proc view of
 * this same process. Allocating a large array between two snapshots is the
 * one thing the test can control on any machine: it must show up on both
 * sides, because it is real memory either way.
 */
final class MemoryReporterTest extends TestCase
{
    public function testSnapshotCarriesBothThePhpAndTheOsView(): void
    {
        $snapshot = new MemoryReporter()->snapshot();

        self::assertGreaterThan(0, $snapshot->phpUsage);
        self::assertGreaterThan(0, $snapshot->rss);
    }

    public function testAllocatingMemoryIsVisibleInBothViewsOnTheNextSnapshot(): void
    {
        $reporter = new MemoryReporter();
        $before = $reporter->snapshot();

        // 1M entries cost real memory on both sides - the PHP engine's array
        // buckets and the OS pages backing them - well under the CLI's
        // default 128M memory_limit.
        $hold = array_fill(0, 1_000_000, 0);

        $after = $reporter->snapshot();
        $diff = $reporter->diff($before, $after);

        self::assertGreaterThan(0, $diff->phpUsage);
        self::assertGreaterThan(0, $diff->rss);

        unset($hold);
    }
}
