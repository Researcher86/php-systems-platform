<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Domain;

use InvalidArgumentException;
use PhpJobQueue\Producer\Producer;
use PhpSystemsPlatform\Queue\Jobs\ProductIndexJob;
use PhpSystemsPlatform\Storage\Repositories\ProductRepository;

/**
 * Creating, changing and deleting products — the catalog's first write path
 * (PLAN Step 41) — and keeping php-mini-search in step with it.
 *
 * The same dual write as orders: the row first, then a `product.index` job.
 * A crash between the two leaves the index behind until the next change to
 * that product or the next full reindex, which is the documented limit
 * (docs/failure-handling.md). The job reads the row as it is when it runs, and
 * the row's version orders it against any other job for the same product,
 * so a late or repeated job cannot undo a newer change.
 */
final readonly class ProductService
{
    private const string SKU = '/^[A-Za-z0-9._-]{1,64}$/';

    public function __construct(
        private ProductRepository $products,
        private ?Producer $producer = null,
    ) {
    }

    /**
     * @throws InvalidArgumentException on an invalid sku, title, description or price
     */
    public function put(string $sku, mixed $title, mixed $description, mixed $price): ProductRecord
    {
        $product = $this->products->save(
            $this->sku($sku),
            $this->text($title, 'title', 255, required: true),
            $this->text($description ?? '', 'description', 4000, required: false),
            $this->price($price),
        );
        $this->index($product);

        return $product;
    }

    /** Null when the sku was never written. */
    public function delete(string $sku): ?ProductRecord
    {
        $product = $this->products->delete($this->sku($sku));
        if ($product !== null) {
            $this->index($product);
        }

        return $product;
    }

    private function index(ProductRecord $product): void
    {
        // Keyed by the version: each version of a product is indexed once,
        // and a redelivery of the same version is recognized as one.
        $this->producer?->dispatch(
            ProductIndexJob::TYPE,
            ['sku' => $product->sku],
            idempotencyKey: sprintf('product.index:%s:%d', $product->sku, $product->version),
        );
    }

    private function sku(string $sku): string
    {
        if (preg_match(self::SKU, $sku) !== 1) {
            throw new InvalidArgumentException('sku must be 1-64 characters of A-Z, a-z, 0-9, ".", "_" or "-".');
        }

        return $sku;
    }

    /**
     * Bytes, not characters: the mini database caps VARCHAR in bytes.
     */
    private function text(mixed $value, string $field, int $maxBytes, bool $required): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException("{$field} must be a string.");
        }
        $value = trim($value);
        if ($required && $value === '') {
            throw new InvalidArgumentException("{$field} must not be empty.");
        }
        if (strlen($value) > $maxBytes) {
            throw new InvalidArgumentException("{$field} must not exceed {$maxBytes} bytes.");
        }

        return $value;
    }

    /**
     * A string or a number, at most two decimals, at most DECIMAL(10,2).
     */
    private function price(mixed $price): string
    {
        $price = is_int($price) || is_float($price) ? (string) $price : $price;
        if (!is_string($price) || preg_match('/^\d{1,8}(\.\d{1,2})?$/', $price) !== 1) {
            throw new InvalidArgumentException('price must be a non-negative amount with at most two decimals.');
        }

        return number_format((float) $price, 2, '.', '');
    }
}
