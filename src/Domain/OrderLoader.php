<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Domain;

/**
 * Assemble an order snapshot - the seam the concurrency phase is built on.
 *
 * Two implementations answer it identically and differ only in how they
 * spend the wait: SequentialOrderLoader does the reads one after another in
 * this process, Workers\ConcurrentOrderLoader fans the independent ones out
 * to worker processes. Callers (the background job, the CLI comparison)
 * depend on this interface, so swapping the execution model never changes
 * what they get back.
 */
interface OrderLoader
{
    /**
     * @return OrderSnapshot|null null when no such order exists - a missing
     *                            order is the only reason a snapshot cannot
     *                            be built
     */
    public function load(string $id): ?OrderSnapshot;
}
