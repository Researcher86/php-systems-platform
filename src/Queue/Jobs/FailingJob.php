<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Queue\Jobs;

use PhpSystemsPlatform\Queue\Job;
use PhpSystemsPlatform\Queue\JobContext;
use RuntimeException;

/**
 * PLAN Step 22's deliberate failure: a job published only to reproduce what
 * happens to one that keeps failing.
 *
 * It throws on every delivery, on purpose, so it exercises the whole
 * failure path end to end - a worker answers job_failed, the dispatcher
 * retries a well-formed job (this one has no ValidatesPayload, so
 * shouldRetry() lets it spend its whole budget), and after max_attempts it
 * is retired into the dead FAILED state instead of retried forever. The
 * integration tests and `failure:demo` pin exactly that: job fails -> retry
 * -> failure -> dead/failed state.
 */
final readonly class FailingJob implements Job
{
    public const string TYPE = 'demo.failing';

    public function execute(JobContext $context): void
    {
        throw new RuntimeException('Injected failure (demo.failing).');
    }
}
