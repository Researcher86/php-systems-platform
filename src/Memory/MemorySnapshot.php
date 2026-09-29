<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Memory;

/**
 * One process at one moment, from both sides: what the PHP engine thinks it
 * holds, and what the kernel backs with pages (null without /proc).
 */
final readonly class MemorySnapshot
{
    public function __construct(
        /** memory_get_usage() - bytes the engine's allocator has handed out */
        public int $phpUsage,
        /** memory_get_usage(true) - bytes the engine has requested from the OS */
        public int $phpRealUsage,
        /** VmRSS - resident pages, shared and private together */
        public ?int $rss,
        /** RssAnon - resident pages private to this process (post-COW) */
        public ?int $privateMemory,
        /** RssShmem - resident pages still shared with another process */
        public ?int $sharedMemory,
    ) {
    }
}
