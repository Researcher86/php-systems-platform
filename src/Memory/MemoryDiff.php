<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Memory;

/**
 * The difference between two MemorySnapshots, field by field. A null side on
 * either snapshot (no /proc on this host) makes the corresponding diff field
 * null too, rather than a nonsensical number.
 */
final readonly class MemoryDiff
{
    public function __construct(
        public int $phpUsage,
        public int $phpRealUsage,
        public ?int $rss,
        public ?int $privateMemory,
        public ?int $sharedMemory,
    ) {
    }
}
