<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Domain;

/**
 * An immutable order as the API sees it.
 *
 * `product` is the sku of the catalog entry the order is for - the key the
 * enrichment loads (product, stock level) start from.
 *
 * `amount` is deliberately a string: the database stores DECIMAL(10,2) as a
 * fixed-scale string and money that travels through a JSON round trip should
 * not first become a float. The API accepts numbers and normalizes them to
 * "12.34" before anything is persisted.
 */
final readonly class Order
{
    public function __construct(
        public string $id,
        public string $customer,
        public string $amount,
        public string $product,
        public OrderStatus $status,
        public string $createdAt,
        public string $updatedAt,
    ) {
    }
}
