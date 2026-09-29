<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Application\Handlers;

use PhpSystemsPlatform\Http\Request;
use PhpSystemsPlatform\Http\Response;
use PhpSystemsPlatform\Observability\MetricsReporter;

/**
 * GET /metrics - the MetricsReporter snapshot (the same one the `metrics`
 * CLI prints) as plain text, one `name value` per line. The metric names
 * are the contract; floats are fixed at four decimals.
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
