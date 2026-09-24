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
 * The background work an order actually needs (PLAN Step 13): load the whole
 * picture - order, customer, product, stock level - and settle the order
 * from it.
 *
 * It is the platform's example of a job whose cost is I/O rather than CPU,
 * which is why the loading is a seam: the job asks an OrderLoader for a
 * snapshot and does not care whether the reads happened one after another or
 * side by side on worker processes. The context hands it the sequential one,
 * deliberately - this job already runs inside a pool worker, and a worker
 * that fans work back into its own pool competes with the jobs waiting
 * behind it. The fan-out belongs where the caller is not itself a worker;
 * `orders:compare` is that caller.
 *
 * The outcome is a status: stock the catalog can cover completes the order,
 * anything else cancels it. Either way the cached copy is now stale and is
 * dropped, the same invalidate-on-write rule the HTTP update path follows.
 *
 * A missing order_id can never work (PLAN Step 19) - ValidatesPayload
 * rejects it before this job ever reaches a worker. "Order not found" is
 * different: this job, unlike order.created, has no write that guarantees
 * the row exists first (it can be dispatched by hand, an operator's typo
 * and all), so it is a real, reachable failure mode - and answering it needs
 * a database read a payload check cannot do. It stays on the normal retry
 * path.
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

        $orderId = (string) $payload['order_id'];

        if ($context->loader === null) {
            throw new RuntimeException('order.process needs an order loader in its context.');
        }

        $snapshot = $context->loader->load($orderId);

        if ($snapshot === null) {
            throw new RuntimeException(sprintf('Order "%s" not found for order.process.', $orderId));
        }

        $status = $snapshot->stock !== null && $snapshot->stock->available > 0
            ? OrderStatus::COMPLETED
            : OrderStatus::CANCELLED;

        $context->orders->updateOrderStatus($orderId, $status);

        try {
            $context->cache->deleteOrder($orderId);
        } catch (CacheClientException) {
            $context->cache->counters()->bypasses++;
        }
    }
}
