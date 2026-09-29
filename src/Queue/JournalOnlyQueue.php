<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Queue;

use PhpJobQueue\Job\Job;
use PhpJobQueue\Job\JobState;
use PhpJobQueue\Persistence\JobStorage;
use PhpJobQueue\Queue\Queue;
use PhpJobQueue\Support\Clock;
use PhpJobQueue\Support\SystemClock;

/**
 * The producer side of a cross-process queue: push() writes the job to the
 * journal and keeps nothing in memory.
 *
 * serve and queue:publish only ever publish - the jobs are popped by the
 * separate queue:consume process, which restores them from the journal. An
 * InMemoryQueue on this side would also enqueue every job into a list nobody
 * ever drains, so a long-running serve would hold every order it ever
 * accepted until it exits.
 *
 * Nothing is waiting here, so pop() finds nothing and every size is zero;
 * the real depth lives in the journal (see QueueJournal).
 */
final readonly class JournalOnlyQueue implements Queue
{
    public function __construct(
        private JobStorage $storage,
        private Clock $clock = new SystemClock(),
    ) {
    }

    /**
     * Admits a new job the way InMemoryQueue does (READY now, or DELAYED
     * until now + $delay) and journals it; the consumer's restore then
     * schedules it by the availableAt written here.
     */
    public function push(Job $job, int $delay = 0): void
    {
        if ($job->getState() === JobState::CREATED) {
            $now = $this->clock->now();
            $delay > 0 ? $job->markDelayed($now + $delay) : $job->markReady($now);
        }

        $this->storage->store($job->getId()->toString(), $job->toArray());
    }

    public function pop(?float $now = null): ?Job
    {
        return null;
    }

    public function size(): int
    {
        return 0;
    }

    public function readySize(): int
    {
        return 0;
    }

    public function delayedSize(): int
    {
        return 0;
    }

    public function nextDeadline(): ?float
    {
        return null;
    }
}
