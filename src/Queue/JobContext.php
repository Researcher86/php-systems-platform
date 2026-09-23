<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Queue;

use PhpJobQueue\Job\Job as QueueJob;
use PhpSystemsPlatform\Cache\CacheService;
use PhpSystemsPlatform\Domain\OrderLoader;
use PhpSystemsPlatform\Domain\OrderService;

/**
 * Everything a running platform Job is allowed to reach: the queue's own
 * carrier it came from (type, payload, attempt count) and the platform
 * services the current phase's jobs are built on. A job reads its input off
 * the carrier's payload and its truth off the services here - never the other
 * way around.
 */
final readonly class JobContext
{
    public function __construct(
        public QueueJob $job,
        public OrderService $orders,
        public CacheService $cache,
        public ?OrderLoader $loader = null,
    ) {
    }
}
