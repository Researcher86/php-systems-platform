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
 * Step 20 adds what completing really means: settling the order takes one
 * unit of the sku off the shelf (InventoryRepository::decrementAvailable()).
 * THAT write is the reason idempotency exists here - the very next section.
 *
 * ## Why this job needs an idempotency guard (PLAN Step 20)
 *
 * The queue promises at-least-once delivery: a worker that dies after the
 * settle but before its ACK leaves the job PROCESSING, and a restart returns
 * a PROCESSING job to READY so the whole operation is handed to a worker
 * again. A second settle is a second unit taken off the shelf - the same
 * double-apply the component's ChargePaymentJob warns about, on this
 * platform's own side effect. The guard closes the gap for redelivery: a job
 * carrying an idempotency key that the guard already knows has done its work
 * is skipped before any read or write happens, order snapshot included.
 *
 * The key must name the OPERATION, not the delivery - "settle order X", not
 * a job id that changes when the job is recreated. queue:publish takes the
 * key as its third argument, and the demo/integration path publishes
 * `order.process:<order id>`, exactly the shape the component demands. See
 * PhpJobQueue\Idempotency\IdempotencyGuard for the boundaries this does and
 * does not close: the check, the effects and the record are three separate
 * steps, so a crash between them still double-applies - that residual window
 * is what the guard's docblock calls out, and why it exists to be seen.
 *
 * A missing order_id can never work (PLAN Step 19) - ValidatesPayload marks
 * it ineligible for retry (JobRegistry::shouldRetry()), so the one delivery
 * it takes to notice is spent, never the job's whole attempts budget.
 * "Order not found" is
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
        $key = $context->job->getIdempotencyKey();

        // A redelivery of an operation the guard has already recorded is
        // skipped before the loader runs: nothing needs reading and nothing
        // needs writing a second time.
        if ($key !== null && $context->idempotency !== null && $context->idempotency->isProcessed($key)) {
            return;
        }

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

        // Completing is settling: take the unit off the shelf. Optional by
        // design - a context without an inventory write side (the tests and
        // commands predating Step 20) keeps settling off. A COMPLETED status
        // already proves the snapshot had stock, so only the write side needs
        // checking here.
        if ($status === OrderStatus::COMPLETED && $context->inventory !== null) {
            $context->inventory->decrementAvailable($snapshot->stock->sku);
        }

        try {
            $context->cache->deleteOrder($orderId);
        } catch (CacheClientException) {
            $context->cache->counters()->bypasses++;
        }

        // Recorded last, after every side effect: this line is what a crash
        // between the effects and here would miss, and the guard's docblock
        // is the honest account of what that residual gap means.
        if ($key !== null && $context->idempotency !== null) {
            $context->idempotency->markProcessed($key);
        }
    }
}
