<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Domain;

/**
 * A customer as the reference catalog knows them - the row an order's
 * `customer` name points at.
 *
 * Reference data, not order data: the platform seeds it once and only ever
 * reads it, which is exactly what makes it one of the independent loads the
 * concurrency phase fans out. An order whose customer was never seeded is
 * not an error - the snapshot simply carries no customer.
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
     * Hydrate from one database row - or from the same row after it has
     * travelled through a worker as JSON, which is why this lives on the
     * type instead of inside the repository.
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
