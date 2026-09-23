<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Queue\Jobs;

use PhpSystemsPlatform\Queue\Job;
use PhpSystemsPlatform\Queue\JobContext;

/**
 * A deliberate no-op job, published only by the queue benchmark (PLAN
 * Step 12).
 *
 * The benchmark measures the queue → consumer → worker-pool → job path, so
 * the job itself must not be the bottleneck or a flaky dependency: it does
 * no database work (that is Step 13's own measurement), it only exists to
 * give the pipeline something to move and complete. Keeping it in the
 * registry means the benchmark exercises the exact same dispatch, forwarding
 * and attribution machinery as a real job.
 */
final readonly class NoopJob implements Job
{
    public const string TYPE = 'bench.noop';

    public function execute(JobContext $context): void
    {
        // Intentionally nothing: the cost measured is the pipeline's.
    }
}
