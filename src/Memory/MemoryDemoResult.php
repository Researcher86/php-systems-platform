<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Memory;

/**
 * The three snapshots PLAN Step 15 asks for, in order: the parent before it
 * forks, the child right after forking, and the same child after it writes
 * to the memory it inherited.
 */
final readonly class MemoryDemoResult
{
    public function __construct(
        public MemorySnapshot $beforeFork,
        public MemorySnapshot $afterFork,
        public MemorySnapshot $afterModification,
    ) {
    }
}
