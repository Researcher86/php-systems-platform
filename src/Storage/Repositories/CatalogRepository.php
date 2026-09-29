<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Storage\Repositories;

use PhpSystemsPlatform\Domain\Customer;
use PhpSystemsPlatform\Domain\Product;
use PhpSystemsPlatform\Domain\StockLevel;
use PhpSystemsPlatform\Storage\Database;

/**
 * The reference data an order is enriched with: the customer behind the
 * name, the product behind the sku, and that sku's stock level.
 *
 * Each finder is exactly one round trip - the independent units the order
 * loaders run one after another (SequentialOrderLoader) or side by side on
 * worker processes (ConcurrentOrderLoader).
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
