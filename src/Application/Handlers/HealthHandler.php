<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Application\Handlers;

use PhpSystemsPlatform\Http\Request;
use PhpSystemsPlatform\Http\Response;

/**
 * GET /health - the liveness probe, and the canonical handler shape:
 * Request plus route parameters in, Response out.
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
