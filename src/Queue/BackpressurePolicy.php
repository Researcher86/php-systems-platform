<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Queue;

/**
 * PLAN Step 17: a configurable MAX_QUEUE_SIZE and one explicit policy at it -
 * reject. No blocking (the single HTTP worker cannot drain the queue itself)
 * and no silent delay: a producer asking while the queue is full is told so
 * immediately.
 *
 * `depth` comes from the journal, the same cross-process count
 * `GET /queue/status` reports. An in-memory counter would be wrong here: the
 * HTTP process only ever pushes (queue:consume pops, in another process), so
 * its own count could only grow.
 */
final readonly class BackpressurePolicy
{
    public function __construct(
        private QueueJournal $journal,
        private int $maxSize,
    ) {
    }

    public function evaluate(): BackpressureDecision
    {
        $depth = $this->journal->snapshot()['depth'];

        return new BackpressureDecision(
            depth: $depth,
            maxSize: $this->maxSize,
            atCapacity: $depth >= $this->maxSize,
        );
    }
}
