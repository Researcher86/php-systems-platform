<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Queue\Jobs;

use PhpSystemsPlatform\Queue\Job;
use PhpSystemsPlatform\Queue\JobContext;
use PhpSystemsPlatform\Queue\ValidatesPayload;
use RuntimeException;

/**
 * A job that takes its time and then succeeds - the deliberate counterpart to
 * FailingJob, and the lever that makes a job's DURATION a property of the
 * test instead of a race.
 *
 * Every other job on this platform finishes in milliseconds, which is
 * correct for production and useless for the questions only a slow job can
 * answer: how long is this worker really busy, which worker is holding this
 * job right now, what does a SIGTERM do to work that is still in flight.
 * Asking those questions against a millisecond job means winning a race
 * against a scheduler, and a test that wins a race proves nothing when it
 * loses one. So the platform carries one job whose only behavior is to take
 * the time it was told to take: the end-to-end suite publishes it to hold a
 * pool worker busy long enough to read the pool's own bookkeeping, to kill
 * that worker from the outside and watch the queue recover the job, and to
 * ask a consumer to shut down while work is still running.
 *
 * The range is bounded on purpose, and the bound is where it is for a
 * reason: a sleep longer than the pool's task timeout (5s by default) is a
 * job whose client gives up before its handler does - the forwarder times
 * out, the attempt is failed, and the worker goes on sleeping with nobody
 * waiting for it. Longer than the pool's execution timeout (30s) and the
 * pool kills the worker outright, which is a different failure than the one
 * being staged. So this job refuses a payload outside that window instead of
 * quietly reproducing a fault nobody asked for.
 *
 * It fails on nothing: the one thing it can be wrong about is its own
 * payload, and ValidatesPayload says so before a worker is ever asked.
 */
final readonly class DemoSlowJob implements Job, ValidatesPayload
{
    public const string TYPE = 'demo.slow';

    /**
     * The pool's execution timeout: a job asking to outlive it is asking to
     * be killed, which is Step 22's story, not this one.
     */
    public const float MAX_SECONDS = 30.0;

    /**
     * @param array<string, mixed> $payload
     *
     * @return non-empty-string|null
     */
    public static function validate(array $payload): ?string
    {
        $seconds = $payload['seconds'] ?? null;

        if (!is_int($seconds) && !is_float($seconds)) {
            return 'demo.slow payload is missing a numeric "seconds".';
        }

        if ((float) $seconds <= 0.0 || (float) $seconds > self::MAX_SECONDS) {
            return sprintf('demo.slow "seconds" must be within (0, %s].', self::MAX_SECONDS);
        }

        return null;
    }

    public function execute(JobContext $context): void
    {
        $payload = $context->job->getPayload();
        $reason = self::validate($payload);

        if ($reason !== null) {
            throw new RuntimeException($reason);
        }

        usleep((int) ((float) $payload['seconds'] * 1_000_000));
    }
}
