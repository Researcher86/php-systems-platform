<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Queue;

/**
 * PLAN Step 17: a configurable MAX_QUEUE_SIZE and one explicit policy for
 * what happens at it - reject. No block, no silent delay: a producer that
 * asks while the queue is full gets told so immediately, rather than
 * blocking an HTTP worker (there is only one) on a queue it cannot drain
 * itself, or accepting work its own journal already shows it cannot keep up
 * with.
 *
 * `depth` is read straight off QueueJournal::snapshot() - the same
 * cross-process, durable count `GET /queue/status` answers with - rather
 * than an in-memory counter. The HTTP process's own Producer never pops
 * anything (queue:consume, a different process, does that), so an in-memory
 * count could only ever grow; the journal is the one place both sides of
 * the queue agree on how much work is actually outstanding.
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
