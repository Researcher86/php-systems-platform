<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Domain;

/**
 * A product as the catalog stores it: what Product carries for an order,
 * plus what searching and editing it need — the description the index reads,
 * the version every write bumps, and the soft-delete flag.
 *
 * Product stays as it was, so the order responses that embed one do not
 * change shape.
 */
final readonly class ProductRecord
{
    public function __construct(
        public string $sku,
        public string $title,
        public string $description,
        public string $price,
        public int $version,
        public bool $deleted,
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
            description: (string) $row['description'],
            price: (string) $row['price'],
            version: (int) $row['version'],
            deleted: (bool) $row['deleted'],
        );
    }

    /**
     * @return array{sku: string, title: string, description: string, price: string, version: int}
     */
    public function toArray(): array
    {
        return ['sku' => $this->sku, 'title' => $this->title, 'description' => $this->description, 'price' => $this->price, 'version' => $this->version];
    }
}
