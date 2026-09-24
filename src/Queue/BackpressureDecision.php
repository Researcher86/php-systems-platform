<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Queue;

/**
 * One answer to "can the queue take more work right now" - PLAN Step 17.
 *
 * Carries the numbers along with the verdict so a caller that rejects a
 * request can say why, instead of a bare 429.
 */
final readonly class BackpressureDecision
{
    public function __construct(
        public int $depth,
        public int $maxSize,
        public bool $atCapacity,
    ) {
    }
}
