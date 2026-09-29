<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Domain;

/**
 * A customer as the reference catalog knows them - the row an order's
 * `customer` name points at. Seeded reference data; an order whose customer
 * was never seeded is not an error, its snapshot simply carries none.
 */
final readonly class Customer
{
    public function __construct(
        public string $name,
        public string $tier,
        public string $since,
    ) {
    }

    /**
     * Hydrate from a database row - or the same row after a round trip
     * through a worker as JSON, which is why this lives on the type.
     *
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            name: (string) $row['name'],
            tier: (string) $row['tier'],
            since: (string) $row['since'],
        );
    }
}
