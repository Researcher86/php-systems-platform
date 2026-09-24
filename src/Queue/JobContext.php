<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Queue;

use PhpJobQueue\Idempotency\IdempotencyGuard;
use PhpJobQueue\Job\Job as QueueJob;
use PhpSystemsPlatform\Cache\CacheService;
use PhpSystemsPlatform\Domain\OrderLoader;
use PhpSystemsPlatform\Domain\OrderService;
use PhpSystemsPlatform\Storage\Repositories\InventoryRepository;

/**
 * Everything a running platform Job is allowed to reach: the queue's own
 * carrier it came from (type, payload, attempt count, idempotency key) and
 * the platform services the current phase's jobs are built on. A job reads
 * its input off the carrier's payload and its truth off the services here -
 * never the other way around.
 *
 * Step 20's additions are both seamed the way the rest of the context is: an
 * optional `idempotency` guard (null when the executor has no idempotency
 * store wired, so a job still runs exactly as it always did) and an optional
 * `inventory` write-side (null where nothing is allowed to write stock, so
 * contexts that predate the settle keep their exact behavior).
 */
final readonly class JobContext
{
    public function __construct(
        public QueueJob $job,
        public OrderService $orders,
        public CacheService $cache,
        public ?OrderLoader $loader = null,
        public ?IdempotencyGuard $idempotency = null,
        public ?InventoryRepository $inventory = null,
    ) {
    }
}
