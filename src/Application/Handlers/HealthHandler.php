<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Application\Handlers;

use PhpSystemsPlatform\Http\Request;
use PhpSystemsPlatform\Http\Response;

/**
 * GET /health - the liveness probe.
 *
 * Invokable so the router can register it and call it like any handler
 * closure; kept a class so the shape of a handler (Request plus route
 * parameters in, Response out) has one canonical example to copy.
 */
final class HealthHandler
{
    /**
     * @param array<string, string> $params route parameters (none for /health)
     */
    public function __invoke(Request $request, array $params): Response
    {
        return Response::json([
            'status' => 'ok',
            'service' => 'php-systems-platform',
        ]);
    }
}
