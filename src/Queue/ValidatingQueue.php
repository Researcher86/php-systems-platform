<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Queue;

use PhpJobQueue\Job\Job as QueueJob;
use PhpJobQueue\Persistence\JobStorage;
use PhpJobQueue\Queue\Queue as QueueInterface;

/**
 * PLAN Step 19's enforcement point: a Queue decorator that fails a job
 * before it is ever handed to a worker, when the platform already knows -
 * from the payload alone - that it can never succeed.
 *
 * The component's own retry decision (JobDispatcher::handleFailure()) is
 * `attempts < maxAttempts`, with no hook for "and don't bother" - read
 * straight from its source, not guessed. The only lever this platform has
 * is choosing not to dispatch a job at all, which is exactly what pop()
 * does here: a job that fails JobRegistry::validate() is marked
 * PROCESSING then FAILED - the same two transitions a real dispatch and an
 * exhausted retry would produce, just without ever occupying a worker - and
 * skipped, so the caller sees the next job instead. One attempt is
 * consumed, not the job's whole budget: the honest cost of the delivery it
 * took to notice.
 *
 * Every other Queue method is a plain delegation. Validation only matters
 * at the one moment a job is about to be dispatched.
 */
final readonly class ValidatingQueue implements QueueInterface
{
    public function __construct(
        private QueueInterface $inner,
        private ?JobStorage $storage = null,
    ) {
    }

    public function push(QueueJob $job, int $delay = 0): void
    {
        $this->inner->push($job, $delay);
    }

    public function pop(?float $now = null): ?QueueJob
    {
        while (($job = $this->inner->pop($now)) !== null) {
            $reason = JobRegistry::validate($job->getType(), $job->getPayload());

            if ($reason === null) {
                return $job;
            }

            $job->markProcessing();
            $job->markFailed();
            $this->storage?->store($job->getId()->toString(), $job->toArray());
        }

        return null;
    }

    public function size(): int
    {
        return $this->inner->size();
    }

    public function readySize(): int
    {
        return $this->inner->readySize();
    }

    public function delayedSize(): int
    {
        return $this->inner->delayedSize();
    }

    public function nextDeadline(): ?float
    {
        return $this->inner->nextDeadline();
    }
}
