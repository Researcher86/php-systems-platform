<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Queue;

use Closure;
use PhpJobQueue\Job\Job as QueueJob;
use PhpSystemsPlatform\Queue\Jobs\NoopJob;
use PhpSystemsPlatform\Queue\Jobs\OrderCreatedJob;
use PhpSystemsPlatform\Queue\Jobs\OrderProcessJob;
use RuntimeException;
use Throwable;

/**
 * The type → handler map of every platform background job.
 *
 * The component's carrier only knows its type string; the platform's Job
 * interface is the behavior that type means. This registry is the one place
 * that pairing lives, so a consumer worker can answer "what does a
 * `order.created` carrier run?" without the handler ever doing its own
 * switch. A type nobody registered is not a silent skip - it is a failed
 * attempt, exactly like a malformed payload, so it surfaces through the same
 * retry/DLQ path instead of disappearing.
 */
final class JobRegistry
{
    /**
     * @var array<string, class-string<Job>>
     */
    private const array HANDLERS = [
        OrderCreatedJob::TYPE => OrderCreatedJob::class,
        OrderProcessJob::TYPE => OrderProcessJob::class,
        NoopJob::TYPE => NoopJob::class,
    ];

    public function execute(QueueJob $carrier, JobContext $context): void
    {
        $class = self::HANDLERS[$carrier->getType()] ?? null;

        if ($class === null) {
            throw new RuntimeException(sprintf('No platform job is registered for type "%s".', $carrier->getType()));
        }

        new $class()->execute($context);
    }

    /**
     * PLAN Step 19's pre-flight check (see ValidatesPayload): a reason this
     * payload can never succeed, or null if either the type opted out of a
     * check or the payload passed it. An unregistered type has no opinion
     * here - execute() is where that becomes the failure it is.
     *
     * @param array<string, mixed> $payload
     *
     * @return non-empty-string|null
     */
    public static function validate(string $type, array $payload): ?string
    {
        $class = self::HANDLERS[$type] ?? null;

        if ($class === null || !is_a($class, ValidatesPayload::class, true)) {
            return null;
        }

        return $class::validate($payload);
    }

    /**
     * The retry-eligibility hook JobDispatcher::handleFailure() consults
     * before its own attempts-remaining check (PLAN Step 19, "do not retry
     * every possible error"): a payload validate() has already ruled out
     * can never succeed regardless of how many attempts are left, so this
     * refuses eligibility for exactly the reason a pre-dispatch check would -
     * just answered at the point the component now offers for it, after a
     * real execution has actually failed, instead of guessed at pop() time
     * against a queue decorator built solely to ask the question early.
     *
     * @return Closure(QueueJob, Throwable): bool
     */
    public static function shouldRetry(): Closure
    {
        return static fn (QueueJob $job, Throwable $exception): bool => self::validate($job->getType(), $job->getPayload()) === null;
    }
}
