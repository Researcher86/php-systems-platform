<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Tests\Unit;

use PhpSystemsPlatform\Workers\WorkerMemoryBenchmark;
use PHPUnit\Framework\TestCase;

/**
 * The arithmetic half of PLAN Step 16 - turning the per-worker answers
 * memory.hold sends back into the comparison the CLI prints - tested
 * against fixed numbers instead of a real pool. Workers\WorkerMemoryTasksTest
 * covers what one worker actually reports; ServeIntegrationTest covers a
 * real pool answering for real.
 */
final class WorkerMemoryBenchmarkTest extends TestCase
{
    public function testAggregatesRssAcrossEveryWorkerPlusTheParent(): void
    {
        $report = new WorkerMemoryBenchmark()->aggregate(
            workers: 2,
            parentRss: 10_000_000,
            answers: [
                ['pid' => 101, 'before' => self::snapshot(rss: 5_000_000), 'after' => self::snapshot(rss: 8_000_000)],
                ['pid' => 102, 'before' => self::snapshot(rss: 5_200_000), 'after' => self::snapshot(rss: 8_400_000)],
            ],
        );

        self::assertSame(2, $report['workers']);
        self::assertSame(2, $report['workers_observed']);
        self::assertSame(10_000_000, $report['parent_rss']);

        self::assertSame(5_100_000, $report['before_avg_rss']);
        self::assertSame(8_200_000, $report['after_avg_rss']);

        // Parent plus every worker's own RSS - the number that actually
        // grows with worker count.
        self::assertSame(10_000_000 + 5_000_000 + 5_200_000, $report['total_before_rss']);
        self::assertSame(10_000_000 + 8_000_000 + 8_400_000, $report['total_after_rss']);
    }

    public function testAnAllNullFieldStaysNullInsteadOfBecomingZero(): void
    {
        $report = new WorkerMemoryBenchmark()->aggregate(
            workers: 1,
            parentRss: null,
            answers: [
                ['pid' => 101, 'before' => self::snapshot(rss: null), 'after' => self::snapshot(rss: null)],
            ],
        );

        self::assertNull($report['parent_rss']);
        self::assertNull($report['before_avg_rss']);
        self::assertNull($report['total_before_rss']);
    }

    /**
     * @return array{phpUsage: int, phpRealUsage: int, rss: ?int, privateMemory: ?int, sharedMemory: ?int}
     */
    private static function snapshot(?int $rss): array
    {
        return [
            'phpUsage' => 1_000_000,
            'phpRealUsage' => 2_000_000,
            'rss' => $rss,
            'privateMemory' => $rss,
            'sharedMemory' => 0,
        ];
    }
}
