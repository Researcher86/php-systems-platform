<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Queue\Jobs;

use PhpSystemsPlatform\Domain\Order;
use PhpSystemsPlatform\Queue\Job;
use PhpSystemsPlatform\Queue\JobContext;
use PhpSystemsPlatform\Queue\ValidatesPayload;
use RuntimeException;

/**
 * "An order was created", dispatched by the write path right after the
 * authoritative row lands: re-read the order from the database and warm the
 * derived cache entry.
 *
 * The synchronous populate-on-write in OrderCreateHandler usually did that
 * already, so this is often an idempotent TTL refresh; its real work is
 * healing the gap when the write path had to bypass a cache that was down.
 *
 * Two failures, two futures (PLAN Step 19). A payload without order_id can
 * never work, so ValidatesPayload makes JobRegistry::shouldRetry() refuse a
 * retry. An unknown order_id should be unreachable (the row is written
 * before the job is published) but a payload check cannot rule it out, so
 * it stays a thrown RuntimeException on the normal retry path.
 *
 * The write path keys it `order.created:<order id>` (PLAN Step 20). The work
 * is cheap to repeat, but a known key still skips the database read.
 */
final readonly class OrderCreatedJob implements Job, ValidatesPayload
{
    public const string TYPE = 'order.created';

    public static function validate(array $payload): ?string
    {
        $orderId = $payload['order_id'] ?? null;

        return is_string($orderId) && $orderId !== '' ? null : 'order.created payload is missing order_id.';
    }

    public function execute(JobContext $context): void
    {
        // Checked again here because nothing validates before dispatch: this
        // is the failure shouldRetry() then declines to retry.
        $payload = $context->job->getPayload();
        $reason = self::validate($payload);

        if ($reason !== null) {
            throw new RuntimeException($reason);
        }

        if ($context->alreadyProcessed()) {
            return;
        }

        $orderId = (string) $payload['order_id'];

        // Guarded fill, not a plain set: an order.process worker may settle
        // the order (and delete its key) while this one reads it.
        $order = $context->cache->loadAndFillOrder($orderId, static fn (): ?Order => $context->orders->getOrder($orderId));

        if ($order === null) {
            throw new RuntimeException(sprintf('Order "%s" not found for order.created.', $orderId));
        }

        $context->markProcessed();
    }
}
