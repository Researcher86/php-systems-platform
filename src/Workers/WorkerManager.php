<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Workers;

use Closure;
use PhpJobQueue\Job\Job;
use PhpWorkerPool\Protocol\Request;
use PhpWorkerPool\Sdk\ServerErrorException;
use PhpWorkerPool\Sdk\WorkerPoolClient;
use RuntimeException;

/**
 * The platform's one seam between the queue and the php-worker-pool: it
 * hands a queue Job to the pool as a `job.execute` task and treats the
 * pool's answer as the job's outcome.
 *
 * This is the "Worker Manager" of PLAN Step 10 - the layer between the
 * queue consumer and the actual worker processes. It does not own any
 * process itself: the pool Master does worker lifecycle, dispatch, failure
 * and shutdown, and this adapter only speaks the client protocol, exactly
 * like ConcurrentTaskRunner speaks it for hash tasks. A task the pool
 * rejects (job_failed) becomes a RuntimeException so the queue's retry and
 * failure accounting sees a failed attempt; a pool that is gone propagates
 * the connection error the same way - a delivery that did not happen.
 */
final readonly class WorkerManager
{
    public function __construct(
        private WorkerPoolClient $client,
    ) {
    }

    /**
     * The php-job-queue WorkerPool handler that turns each of its forked
     * workers into a forwarder: hand the job to the pool, wait for the
     * verdict. The client is built lazily inside the handler, so every
     * forked forwarder dials its own connection instead of inheriting the
     * parent's socket.
     *
     * @return Closure(Job): null
     */
    public static function forwarder(string $socketPath, float $timeoutSeconds): Closure
    {
        $manager = null;

        return static function (Job $job) use (&$manager, $socketPath, $timeoutSeconds): mixed {
            $manager ??= new self(new WorkerPoolClient($socketPath, $timeoutSeconds));
            $manager->execute($job);

            return null;
        };
    }

    public function execute(Job $job): void
    {
        try {
            $this->client->call(new Request('job.execute', [
                'job' => $job->toArray(),
            ]));
        } catch (ServerErrorException $e) {
            $reason = $e->payload['message'] ?? $e->error;

            throw new RuntimeException(sprintf('Worker rejected job: %s', $reason), 0, $e);
        }
    }
}
