<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Workers;

use PhpWorkerPool\Protocol\Request;
use PhpWorkerPool\Protocol\Response;

/**
 * PLAN Step 22's pool-side failure injection: the `worker.crash` task that
 * makes a worker die on demand, so the crash path - Master reaps it, the
 * request it held fails with worker_crashed, and a replacement is forked -
 * can be reproduced instead of only survived.
 *
 * Killing the worker with SIGKILL is deliberate: no cleanup, no chance to
 * answer, no chance to signal anything - exactly what an OOM-kill or a
 * segfault looks like from the pool's side. The Master's SIGCHLD handler
 * (reapCrashedWorkers) is the only witness, which is the point: this task
 * exists so a crash is triggered on demand against the machinery that
 * already detects and replaces crashes.
 *
 * PLAN: "Failure injection should only be enabled in development/demo
 * mode." The wire task is therefore armed only when the platform launches
 * with a development/demo environment (config failure_injection.enabled);
 * a production pool answers failure_injection_disabled instead of dying.
 */
final readonly class WorkerFailureTasks
{
    public function __construct(
        private bool $injectionEnabled,
    ) {
    }

    /**
     * @return \Closure(Request): Response
     */
    public function handler(): \Closure
    {
        return fn (Request $request): Response => match ($request->action) {
            'worker.crash' => $this->crash(),
            default => Response::error('unknown_action'),
        };
    }

    private function crash(): Response
    {
        if (!$this->injectionEnabled) {
            return Response::error('failure_injection_disabled');
        }

        // Never returns: the process is gone before it could answer, so the
        // only report of this death is the pool's own crash-reap path. The
        // exit() is unreachable fallback for a platform where posix_kill is
        // somehow unavailable.
        posix_kill(getmypid(), SIGKILL);
        exit(137);
    }
}
