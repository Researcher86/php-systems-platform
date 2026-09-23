<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Domain;

/**
 * What the inventory says about one sku: how many units are on the shelf and
 * how many are already promised to other orders. The background order
 * processing decides an order's fate from it.
 */
final readonly class StockLevel
{
    public function __construct(
        public string $sku,
        public int $available,
        public int $reserved,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            sku: (string) $row['sku'],
            available: (int) $row['available'],
            reserved: (int) $row['reserved'],
        );
    }
}
