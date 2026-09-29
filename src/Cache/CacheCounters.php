<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Cache;

/**
 * Per-process cache bookkeeping: hits and misses per lookup, sets, deletes,
 * bypasses (the cache was unreachable and the database answered), and
 * abandoned fills (a cache-aside fill dropped because a writer touched the
 * key while the row was being read - see CacheService::loadAndFillOrder()).
 * Bypasses are counted by the callers, which own the degrade decision,
 * except inside loadAndFillOrder(), which degrades on their behalf.
 * Mutable on purpose - the only state the readonly CacheService holds.
 */
final class CacheCounters
{
    public function __construct(
        public int $hits = 0,
        public int $misses = 0,
        public int $sets = 0,
        public int $deletes = 0,
        public int $bypasses = 0,
        public int $abandonedFills = 0,
    ) {
    }
}
