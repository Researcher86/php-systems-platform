<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Queue\Jobs;

use PhpMiniCache\Sdk\CacheClientException;
use PhpSystemsPlatform\Domain\OrderStatus;
use PhpSystemsPlatform\Queue\Job;
use PhpSystemsPlatform\Queue\JobContext;
use PhpSystemsPlatform\Queue\ValidatesPayload;
use RuntimeException;

/**
 * Settle an order (PLAN Step 13/20): load the whole picture - order,
 * customer, product, stock - then complete the order if stock covers it,
 * taking one unit off the shelf, or cancel it. Either way the cached copy is
 * dropped, the same invalidate-on-write rule as the HTTP update path.
 *
 * Loading goes through the OrderLoader seam. The context hands it the
 * sequential loader on purpose: this job already occupies a pool worker, and
 * fanning its reads back into that pool would compete with the jobs queued
 * behind it (`orders:compare` is where the fan-out is measured).
 *
 * ## Idempotency (PLAN Step 20)
 *
 * Delivery is at-least-once: a worker that dies after settling but before
 * its ACK leaves the job PROCESSING, and recovery hands it out again. A
 * second settle would take a second unit off the shelf, so a job whose
 * idempotency key the guard already knows is skipped before any read. The
 * key names the OPERATION (`order.process:<order id>`), not the delivery.
 * The check, the effects and the record are separate steps, so a crash
 * between the effects and the record still double-applies - the residual
 * window IdempotencyGuard documents.
 *
 * A missing order_id can never work, so ValidatesPayload makes
 * JobRegistry::shouldRetry() refuse a retry. "Order not found" is reachable
 * here (the job can be published by hand, typo and all) and needs a read to
 * answer, so it stays on the normal retry path.
 */
final readonly class OrderProcessJob implements Job, ValidatesPayload
{
    public const string TYPE = 'order.process';

    public static function validate(array $payload): ?string
    {
        $orderId = $payload['order_id'] ?? null;

        return is_string($orderId) && $orderId !== '' ? null : 'order.process payload is missing order_id.';
    }

    public function execute(JobContext $context): void
    {
        $payload = $context->job->getPayload();
        $reason = self::validate($payload);

        if ($reason !== null) {
            throw new RuntimeException($reason);
        }

        if ($context->alreadyProcessed()) {
            return;
        }

        $orderId = (string) $payload['order_id'];

        if ($context->loader === null) {
            throw new RuntimeException('order.process needs an order loader in its context.');
        }

        $snapshot = $context->loader->load($orderId);

        if ($snapshot === null) {
            throw new RuntimeException(sprintf('Order "%s" not found for order.process.', $orderId));
        }

        $inStock = $snapshot->stock !== null && $snapshot->stock->available > 0;

        // Take the unit BEFORE writing the status. The snapshot is only a
        // hint - another worker may take the last unit between our read and
        // this write - so the conditional UPDATE decides, and an order that
        // lost the race is cancelled rather than completed unsold. Without an
        // inventory write side the snapshot is trusted and nothing is taken.
        if ($inStock && $context->inventory !== null) {
            $inStock = $context->inventory->decrementAvailable($snapshot->stock->sku);
        }

        $context->orders->updateOrderStatus($orderId, $inStock ? OrderStatus::COMPLETED : OrderStatus::CANCELLED);

        try {
            $context->cache->deleteOrder($orderId);
        } catch (CacheClientException) {
            $context->cache->counters()->bypasses++;
        }

        $context->markProcessed();
    }
}
