<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Queue\Jobs;

use PhpMiniCache\Sdk\CacheClientException;
use PhpSystemsPlatform\Queue\Job;
use PhpSystemsPlatform\Queue\JobContext;
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
 * A payload without an order_id, or an order_id no longer in the database,
 * is a RuntimeException - the job is malformed or the truth it points at is
 * gone, and that must surface as a failed attempt for the retry/DLQ story
 * instead of being swallowed.
 */
final readonly class OrderCreatedJob implements Job
{
    public const string TYPE = 'order.created';

    public function execute(JobContext $context): void
    {
        $orderId = $context->job->getPayload()['order_id'] ?? null;

        if (!is_string($orderId) || $orderId === '') {
            throw new RuntimeException('order.created payload is missing order_id.');
        }

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
