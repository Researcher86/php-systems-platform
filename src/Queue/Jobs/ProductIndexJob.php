<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Queue\Jobs;

use PhpSystemsPlatform\Queue\Job;
use PhpSystemsPlatform\Queue\JobContext;
use PhpSystemsPlatform\Queue\ValidatesPayload;
use RuntimeException;

/**
 * "A product changed": tell php-mini-search what the product is now.
 *
 * The job carries only the sku and reads the row when it runs, never the
 * values at dispatch time. With jobs for one product in flight out of order,
 * whichever runs last still sends the current row, and the row's version lets
 * the search service ignore anything older than what it holds — including a
 * stale write that would otherwise bring a deleted product back.
 *
 * A soft-deleted row becomes a DELETE at its version. A search service that
 * is down fails the attempt (SearchUnavailable), and the queue retries it.
 * No idempotency guard is consulted: repeating the job is already harmless,
 * because the search service answers a repeated version with
 * `applied: false`.
 */
final readonly class ProductIndexJob implements Job, ValidatesPayload
{
    public const string TYPE = 'product.index';

    public static function validate(array $payload): ?string
    {
        $sku = $payload['sku'] ?? null;

        return is_string($sku) && $sku !== '' ? null : 'product.index payload is missing sku.';
    }

    public function execute(JobContext $context): void
    {
        $reason = self::validate($context->job->getPayload());
        if ($reason !== null) {
            throw new RuntimeException($reason);
        }
        if ($context->products === null || $context->search === null) {
            throw new RuntimeException('product.index needs the products repository and the search client.');
        }

        $sku = (string) $context->job->getPayload()['sku'];
        $product = $context->products->find($sku) ?? throw new RuntimeException(sprintf('Product "%s" not found for product.index.', $sku));

        if ($product->deleted) {
            $context->search->delete($product->sku, $product->version);

            return;
        }

        $context->search->put($product->sku, $product->version, $product->title, $product->description);
    }
}
