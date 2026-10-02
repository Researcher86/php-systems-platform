<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Memory;

use RuntimeException;

/**
 * Takes MemorySnapshots (PHP allocator counters plus the kernel's view of
 * resident pages). A failed /proc read leaves the OS fields
 * null instead of throwing, so the PHP half still works off Linux.
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
}
