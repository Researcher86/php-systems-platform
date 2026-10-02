<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Application\Handlers;

use PhpSystemsPlatform\Http\Request;
use PhpSystemsPlatform\Http\Response;
use PhpSystemsPlatform\Observability\MetricsReporter;

/**
 * GET /metrics - the MetricsReporter snapshot as plain text, exactly what
 * the `metrics` CLI prints (see MetricsReporter::toText()).
 */
final readonly class MetricsHandler
{
    public function __construct(
        private MetricsReporter $reporter,
    ) {
    }

    /**
     * @param array<string, string> $params route parameters (none for /metrics)
     */
    public function __invoke(Request $request, array $params): Response
    {
        return Response::text($this->reporter->toText());
    }
}
