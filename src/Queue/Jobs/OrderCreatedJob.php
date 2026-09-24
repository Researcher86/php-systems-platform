<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Queue\Jobs;

use PhpMiniCache\Sdk\CacheClientException;
use PhpSystemsPlatform\Queue\Job;
use PhpSystemsPlatform\Queue\JobContext;
use PhpSystemsPlatform\Queue\ValidatesPayload;
use RuntimeException;

/**
 * The first background job: "an order was created", dispatched by the write
 * path right after the authoritative row lands.
 *
 * It exists to make a stale write-visible effect eventual instead of
 * synchronous: it re-reads the order from the database - the source of truth -
 * and warms the derived cache entry. The synchronous populate-on-write in
 * OrderCreateHandler normally did that already, so the job is often an
 * idempotent no-op that simply refreshes the TTL; its real work shows when
 * the write path had to bypass the cache (it was down): once the cache is
 * reachable again, this job heals the gap.
 *
 * Two ways to fail, two different futures (PLAN Step 19). A payload without
 * an order_id can never work - retrying it asks the same unanswerable
 * question again - so ValidatesPayload rejects it before this job is ever
 * dispatched. An order_id the database does not know is different: in this
 * platform's write-before-publish design that should never actually happen
 * (the row exists before the job is even created), but it is not something
 * a payload check can rule out - answering it needs the database this job
 * already has open - so it stays a thrown RuntimeException on the normal
 * retry path, the honest place for a condition that is unreachable in
 * practice rather than provably permanent.
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
        // validate() already ruled this out for anything dispatched through
        // ValidatingQueue; called again here so a job executed directly (a
        // test, or any future path that bypasses the queue) gets the same
        // answer instead of a silently different one.
        $payload = $context->job->getPayload();
        $reason = self::validate($payload);

        if ($reason !== null) {
            throw new RuntimeException($reason);
        }

        $orderId = (string) $payload['order_id'];
        $order = $context->orders->getOrder($orderId);

        if ($order === null) {
            throw new RuntimeException(sprintf('Order "%s" not found for order.created.', $orderId));
        }

        try {
            $context->cache->setOrder($order);
        } catch (CacheClientException) {
            $context->cache->counters()->bypasses++;
        }
    }
}
