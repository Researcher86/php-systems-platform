<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Queue\Jobs;

use PhpSystemsPlatform\Queue\Job;
use PhpSystemsPlatform\Queue\JobContext;
use PhpSystemsPlatform\Queue\ValidatesPayload;
use RuntimeException;

/**
 * A job that sleeps for the given number of seconds and succeeds - the lever
 * that makes a job's DURATION a property of the test instead of a race.
 *
 * Every real job finishes in milliseconds, too fast to observe which worker
 * holds it, to kill that worker mid-job and watch the queue recover it, or to
 * shut a consumer down with work still in flight. The end-to-end suite
 * publishes this job for exactly those scenarios.
 *
 * The duration is capped below the pool's task timeout: a longer sleep would
 * time out at the forwarder on every attempt (queue:consume uses the same
 * number as its visibility timeout), be redelivered, and never succeed. A
 * payload outside the window is refused rather than reproducing that fault.
 */
final readonly class DemoSlowJob implements Job, ValidatesPayload
{
    public const string TYPE = 'demo.slow';

    /** The workers.task_timeout default (5s) minus a second of round-trip headroom. */
    public const float MAX_SECONDS = 4.0;

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
