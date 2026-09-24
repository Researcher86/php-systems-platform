<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Storage\Repositories;

use PhpSystemsPlatform\Storage\Database;

/**
 * The write-side of the inventory table - the counterpart of CatalogRepository,
 * which only ever reads it.
 *
 * Reading stays on CatalogRepository, because completing the Platform Step 20
 * demonstration changes what "reference data" means: the catalog is seeded by
 * the migration and read-only for the concurrency loaders, but settling a
 * completed order takes a unit off the shelf. That write is the platform's
 * example of a side effect that MUST NOT run twice - if a queue redelivers an
 * already-settled order.process and the job settles blindly, the same order
 * ships two units' worth of stock.
 *
 * The decrement is guarded in SQL (a row that is already at zero is refused
 * rather than driven negative) so the danger the idempotency guard addresses
 * stays the double-apply, not an assertion about stock the loaders cannot see.
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
