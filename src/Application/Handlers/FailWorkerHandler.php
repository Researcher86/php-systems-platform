<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Application\Handlers;

use PhpSystemsPlatform\Http\Request;
use PhpSystemsPlatform\Http\Response;
use PhpSystemsPlatform\Workers\WorkerFailureInjector;

/**
 * PLAN Step 22's HTTP side of the controlled failure mode: POST
 * /debug/fail-worker crashes one pool worker and answers with the evidence
 * of each phase - the worker died, the pool detected it, the dead pid was
 * removed, and a replacement was started.
 *
 * The route exists only in development/demo environments: the Application
 * registers it exactly when serve() hands it an injector, and serve() builds
 * one only when config failure_injection.enabled is true. A production
 * serve has no such route at all (404), and a handler invoked without an
 * injector - defensive, for a test or a future direct route - answers 503
 * with failure_injection_disabled.
 */
final readonly class FailWorkerHandler
{
    public function __construct(
        private ?WorkerFailureInjector $injector = null,
    ) {
    }

    /**
     * @param array<string, string> $params route parameters (none for /debug/fail-worker)
     */
    public function __invoke(Request $request, array $params): Response
    {
        if ($this->injector === null) {
            return Response::json(['error' => 'failure_injection_disabled'], 503);
        }

        return Response::json($this->injector->crashOneWorker());
    }
}
