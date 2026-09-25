<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Application\Handlers;

use PhpSystemsPlatform\Http\Request;
use PhpSystemsPlatform\Http\Response;
use PhpSystemsPlatform\Observability\MetricsReporter;

/**
 * PLAN Step 23's HTTP side of observability: GET /metrics answers with the
 * platform's standard metric set as plain text, one `name value` per line.
 *
 * The single reader over the shared MetricsReporter, so exactly the same
 * snapshot the `metrics` CLI command prints is what a running serve exposes:
 * counters and gauges from the registry plus the queue/worker/memory numbers
 * pulled live. Plain text rather than a server-specific format because the
 * contract here is the metric names - values that read the same however they
 * are consumed.
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
        return Response::text($this->dump($this->reporter->snapshot()));
    }

    /** @param array<string, int|float> $metrics */
    private function dump(array $metrics): string
    {
        $lines = [];

        foreach ($metrics as $name => $value) {
            if (is_float($value)) {
                $value = sprintf('%.4f', $value);
            }

            $lines[] = sprintf('%s %s', $name, $value);
        }

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }
}
