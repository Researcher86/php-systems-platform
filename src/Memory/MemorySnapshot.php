<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Memory;

/**
 * One measurement of one process, at one moment, from both sides: what the
 * PHP engine itself thinks it holds, and what the kernel actually backs with
 * pages.
 *
 * The two never agree, and that gap is the point of Step 15's demo: a forked
 * child's `rss` starts equal to its parent's (shared pages, nothing copied
 * yet) and only grows once the child writes - `sharedMemory` (RssShmem) and
 * `privateMemory` (RssAnon) are what separate "still shared" from "just
 * copied on write".
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
