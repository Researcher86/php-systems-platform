<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Workers;

use PhpJobQueue\Job\Job;
use PhpSystemsPlatform\Observability\Trace;
use PhpSystemsPlatform\Queue\JobExecutor;
use PhpWorkerPool\Protocol\Request;
use PhpWorkerPool\Protocol\Response;
use Throwable;

/**
 * The pool worker's half of the queue bridge - the `job.execute` task that
 * WorkerManager sends (PLAN Step 10).
 *
 * A worker receives the job as its plain toArray() array and rebuilds the
 * carrier with the component's Job::fromArray(); the payload is opaque to
 * php-worker-pool, which only moves it between processes. Executing it is
 * the platform's business: JobExecutor builds the services this worker needs
 * and runs the job through the registry. A failure is answered as
 * Response::error('job_failed', ...), not a thrown exception, so the pool
 * sees an application outcome rather than a crashed worker - the client
 * turns that error code back into a failed attempt.
 */
final class WorkerJobs
{
    private ?JobExecutor $executor = null;

    /**
     * @param array<string, mixed> $config the platform config, for the
     *                                     database, cache and jobs blocks
     * @param Trace|null           $trace  PLAN Step 24's tracer shared by all
     *                                     workers, or null for none; workers
     *                                     record their job.execute spans into
     *                                     it (and into the journal it was
     *                                     built with)
     */
    public function __construct(
        private array $config,
        private readonly ?Trace $trace = null,
    ) {
    }

    /**
     * @return \Closure(Request): Response
     */
    public function handler(): \Closure
    {
        return fn (Request $request): Response => $request->action === 'job.execute'
            ? $this->executeJob($request->params)
            : Response::error('unknown_action');
    }

    /**
     * @param array<string, mixed> $params
     */
    private function executeJob(array $params): Response
    {
        try {
            $job = Job::fromArray((array) ($params['job'] ?? []));
            $this->executor()->__invoke($job);

            return Response::of(['ok' => true]);
        } catch (Throwable $e) {
            return Response::error('job_failed', ['message' => $e->getMessage()]);
        }
    }

    private function executor(): JobExecutor
    {
        return $this->executor ??= new JobExecutor(
            (array) $this->config['database'],
            (array) $this->config['cache'],
            isset($this->config['jobs']['idempotency_store'])
                ? (string) $this->config['jobs']['idempotency_store']
                : null,
            $this->trace,
        );
    }
}
