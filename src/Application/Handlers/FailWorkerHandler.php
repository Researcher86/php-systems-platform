<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Application\Handlers;

use PhpSystemsPlatform\Http\Request;
use PhpSystemsPlatform\Http\Response;
use PhpSystemsPlatform\Workers\WorkerFailureInjector;

/**
 * POST /debug/fail-worker - crash one pool worker and answer with the
 * evidence of each phase: died, detected, removed, replaced.
 *
 * The route is registered only when serve() builds an injector, i.e. only
 * with failure_injection.enabled (dev/demo); a production serve answers 404.
 * Invoked without an injector anyway, the handler answers 503.
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
