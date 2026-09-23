<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Queue;

/**
 * One unit of platform work, as defined by PLAN.md Step 8.
 *
 * The queue component's PhpJobQueue\Job\Job is the carrier: who made this
 * work, which type it is, how many attempts it has consumed. This interface
 * is what the platform RUNS - the handler's answer to the carrier's arrival.
 * The relationship is one of the explicit seams of the queue phase: the
 * component owns the lifecycle, the platform owns the behavior.
 */
interface Job
{
    public function execute(JobContext $context): void;
}
