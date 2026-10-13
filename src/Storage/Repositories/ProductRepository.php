<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Storage\Repositories;

use PhpSystemsPlatform\Domain\ProductRecord;
use PhpSystemsPlatform\Storage\Database;

/**
 * The catalog's write path, and the reads search needs. Every write bumps
 * the row's version; a delete only sets `deleted`, so the version keeps
 * growing across a delete and a re-create.
 *
 * Read-then-write without a transaction (the platform has none) is safe
 * here for the same reason it is for orders: serve handles one request at a
 * time, so two writes to one sku never interleave.
 */
final readonly class ProductRepository
{
    private const string COLUMNS = 'sku, title, description, price, version, deleted';

    public function __construct(
        private Database $database,
    ) {
    }

    /** The row, deleted or not; null for a sku never written. */
    public function find(string $sku): ?ProductRecord
    {
        $rows = $this->database->read('SELECT ' . self::COLUMNS . ' FROM products WHERE sku = ?', [$sku]);

        return $rows === [] ? null : ProductRecord::fromRow($rows[0]);
    }

    /**
     * Create the product, or replace it — a deleted one comes back. Returns
     * the row as written, at its new version.
     */
    public function save(string $sku, string $title, string $description, string $price): ProductRecord
    {
        $existing = $this->find($sku);

        if ($existing === null) {
            $this->database->write(
                'INSERT INTO products (' . self::COLUMNS . ') VALUES (?, ?, ?, ?, 1, FALSE)',
                [$sku, $title, $description, $price],
            );
        } else {
            $this->database->write(
                'UPDATE products SET title = ?, description = ?, price = ?, version = ?, deleted = FALSE WHERE sku = ?',
                [$title, $description, $price, $existing->version + 1, $sku],
            );
        }

        return $this->find($sku) ?? throw new \RuntimeException("Product {$sku} vanished after it was written.");
    }

    /**
     * Soft-delete. Null when the sku was never written; an already deleted
     * product is returned as it is, without a new version.
     */
    public function delete(string $sku): ?ProductRecord
    {
        $existing = $this->find($sku);

        if ($existing === null || $existing->deleted) {
            return $existing;
        }

        $this->database->write('UPDATE products SET version = ?, deleted = TRUE WHERE sku = ?', [$existing->version + 1, $sku]);

        return $this->find($sku);
    }

    /**
     * Live products among these skus, keyed by sku, in no particular order.
     *
     * @param list<string> $skus
     *
     * @return array<string, ProductRecord>
     */
    public function findLive(array $skus): array
    {
        if ($skus === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($skus), '?'));
        $products = [];
        foreach ($this->database->read('SELECT ' . self::COLUMNS . " FROM products WHERE deleted = FALSE AND sku IN ({$placeholders})", $skus) as $row) {
            $product = ProductRecord::fromRow($row);
            $products[$product->sku] = $product;
        }

        return $products;
    }

    /**
     * Every live product: what a full reindex sends.
     *
     * @return list<ProductRecord>
     */
    public function allLive(): array
    {
        return array_map(
            ProductRecord::fromRow(...),
            $this->database->read('SELECT ' . self::COLUMNS . ' FROM products WHERE deleted = FALSE ORDER BY sku'),
        );
    }
}
