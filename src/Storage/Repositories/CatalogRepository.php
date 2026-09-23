<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Storage\Repositories;

use PhpSystemsPlatform\Domain\Customer;
use PhpSystemsPlatform\Domain\Product;
use PhpSystemsPlatform\Domain\StockLevel;
use PhpSystemsPlatform\Storage\Database;

/**
 * The reference data an order is enriched with: the customer behind the
 * name, the product behind the sku, and the stock level of that sku.
 *
 * One repository rather than three, because the three tables are read the
 * same way and never written by the platform - they are seeded by the
 * migration and only ever looked up by key. Each finder is exactly one
 * round trip, which is what makes them the independent units the
 * concurrency phase either runs one after another (SequentialOrderLoader)
 * or side by side on worker processes (ConcurrentOrderLoader).
 */
final readonly class CatalogRepository
{
    public function __construct(
        private Database $database,
    ) {
    }

    public function findCustomer(string $name): ?Customer
    {
        $rows = $this->database->read('SELECT name, tier, since FROM customers WHERE name = ?', [$name]);

        return $rows === [] ? null : Customer::fromRow($rows[0]);
    }

    public function findProduct(string $sku): ?Product
    {
        $rows = $this->database->read('SELECT sku, title, price FROM products WHERE sku = ?', [$sku]);

        return $rows === [] ? null : Product::fromRow($rows[0]);
    }

    public function findStock(string $sku): ?StockLevel
    {
        $rows = $this->database->read('SELECT sku, available, reserved FROM inventory WHERE sku = ?', [$sku]);

        return $rows === [] ? null : StockLevel::fromRow($rows[0]);
    }
}
