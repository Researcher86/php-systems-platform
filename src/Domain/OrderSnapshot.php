<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Domain;

/**
 * One order at one moment: the authoritative row plus the three pieces of
 * reference data it points at. Those are nullable on purpose - enrichment
 * that was never seeded leaves a hole rather than failing the load.
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
