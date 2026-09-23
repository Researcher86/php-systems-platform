<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Domain;

/**
 * Everything the platform knows about one order at one moment: the
 * authoritative row plus the three pieces of reference data it points at.
 *
 * The three are deliberately nullable. The order is the truth; the catalog
 * around it is enrichment, and a customer, product or stock row that was
 * never seeded must leave a hole in the picture rather than fail the load.
 * Both loaders - sequential and concurrent - answer with exactly this type,
 * which is what makes the two comparable: same result, different execution
 * model.
 */
final readonly class OrderSnapshot
{
    public function __construct(
        public Order $order,
        public ?Customer $customer,
        public ?Product $product,
        public ?StockLevel $stock,
    ) {
    }
}
