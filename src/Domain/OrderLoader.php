<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Domain;

/**
 * Assemble an order snapshot. SequentialOrderLoader reads one after another
 * in this process; Workers\ConcurrentOrderLoader fans the independent reads
 * out to worker processes. Same result, different execution model.
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
