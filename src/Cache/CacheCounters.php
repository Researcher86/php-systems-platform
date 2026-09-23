<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Cache;

/**
 * The counters PLAN wants recorded next to the read path: hits and misses
 * at the lookup, sets when a miss is refilled, deletes on invalidation, and
 * bypasses when the cache itself is unreachable and the request is served
 * straight from the database. Mutable on purpose - the only state the
 * otherwise-immutable CacheService holds.
 */
final class CacheCounters
{
    public function __construct(
        public int $hits = 0,
        public int $misses = 0,
        public int $sets = 0,
        public int $deletes = 0,
        public int $bypasses = 0,
    ) {
    }

    /**
     * @return array{
     *     hits: int,
     *     misses: int,
     *     sets: int,
     *     deletes: int,
     *     bypasses: int,
     * }
     */
    public function snapshot(): array
    {
        return [
            'hits' => $this->hits,
            'misses' => $this->misses,
            'sets' => $this->sets,
            'deletes' => $this->deletes,
            'bypasses' => $this->bypasses,
        ];
    }
}
