<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Memory;

use RuntimeException;

/**
 * The measurement point for PLAN Steps 15 and 16: one snapshot joins PHP's
 * own allocator counters with the OS's view of this process's resident
 * pages; diff() turns two snapshots into what changed.
 *
 * A failed /proc read degrades the OS fields to null rather than throwing -
 * a snapshot still answers the PHP half on a non-Linux host instead of
 * refusing to measure anything at all.
 */
final readonly class MemoryReporter
{
    public function __construct(
        private ProcStatusReader $statusReader = new ProcStatusReader(),
    ) {
    }

    public function snapshot(int $pid = 0): MemorySnapshot
    {
        $status = $this->safeStatus($pid);

        return new MemorySnapshot(
            phpUsage: memory_get_usage(),
            phpRealUsage: memory_get_usage(true),
            rss: self::intOrNull($status['VmRSS'] ?? null),
            privateMemory: self::intOrNull($status['RssAnon'] ?? null),
            sharedMemory: self::intOrNull($status['RssShmem'] ?? null),
        );
    }

    public function diff(MemorySnapshot $before, MemorySnapshot $after): MemoryDiff
    {
        return new MemoryDiff(
            phpUsage: $after->phpUsage - $before->phpUsage,
            phpRealUsage: $after->phpRealUsage - $before->phpRealUsage,
            rss: self::nullableDelta($before->rss, $after->rss),
            privateMemory: self::nullableDelta($before->privateMemory, $after->privateMemory),
            sharedMemory: self::nullableDelta($before->sharedMemory, $after->sharedMemory),
        );
    }

    /**
     * @return array<string, int|string>|null
     */
    private function safeStatus(int $pid): ?array
    {
        try {
            return $this->statusReader->read($pid);
        } catch (RuntimeException) {
            return null;
        }
    }

    private static function intOrNull(int|string|null $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private static function nullableDelta(?int $before, ?int $after): ?int
    {
        if ($before === null || $after === null) {
            return null;
        }

        return $after - $before;
    }
}
