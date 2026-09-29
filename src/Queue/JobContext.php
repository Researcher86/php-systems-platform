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
 * Everything a running platform Job may reach: the queue carrier it came
 * from (type, payload, attempts, idempotency key) and the platform services.
 * A job reads its input off the payload and its truth off the services.
 *
 * `idempotency` is null when no idempotency store is wired and `inventory`
 * is null where nothing may write stock; a job must behave sensibly without
 * either.
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

    /**
     * Whether the guard has already recorded this job's operation - a
     * redelivery to skip before any read or write. False without a key or a
     * guard.
     */
    public function alreadyProcessed(): bool
    {
        $key = $this->job->getIdempotencyKey();

        return $key !== null && $this->idempotency?->isProcessed($key) === true;
    }

    /**
     * Record this job's operation as done. Call it only after every side
     * effect: a crash between the effects and this record is the guard's
     * documented residual double-apply window.
     */
    public function markProcessed(): void
    {
        $key = $this->job->getIdempotencyKey();

        if ($key !== null) {
            $this->idempotency?->markProcessed($key);
        }
    }
}
