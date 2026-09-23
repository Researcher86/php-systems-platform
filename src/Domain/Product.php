<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Domain;

/**
 * A product in the reference catalog, keyed by the sku an order carries.
 *
 * `price` is a string for the same reason Order::$amount is: the column is
 * DECIMAL(10,2) and money that survives a JSON round trip must not first
 * become a float.
 */
final readonly class Product
{
    public function __construct(
        public string $sku,
        public string $title,
        public string $price,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            sku: (string) $row['sku'],
            title: (string) $row['title'],
            price: (string) $row['price'],
        );
    }
}
