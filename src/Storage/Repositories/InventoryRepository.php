<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Storage\Repositories;

use PhpSystemsPlatform\Storage\Database;

/**
 * The write side of the inventory table; CatalogRepository owns the reads.
 *
 * Settling a completed order takes a unit off the shelf - the platform's
 * example of a side effect that MUST NOT run twice: a redelivered
 * order.process that settled blindly would ship two units for one order.
 * The queue-side idempotency guard prevents the double-apply; the SQL guard
 * (available > 0) only keeps the count from going negative.
 */
final readonly class InventoryRepository
{
    public function __construct(
        private Database $database,
    ) {
    }

    /**
     * Take one unit of a sku off the shelf - what settling a COMPLETED order
     * does. Returns false when there is nothing to take (the sku is unknown
     * or its available count is already zero); true reports the unit moved.
     */
    public function decrementAvailable(string $sku): bool
    {
        return $this->database->write(
            'UPDATE inventory SET available = available - 1 WHERE sku = ? AND available > 0',
            [$sku],
        ) === 1;
    }
}
