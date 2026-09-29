<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Support;

use RuntimeException;

/**
 * A minimal HTTP client for the commands that drive a platform by hand (the
 * demo and the failure experiments): a request's status, headers (X-Cache,
 * Retry-After, Location) and body, and nothing else. Load generation uses
 * curl through Benchmarks\HttpLoadTest instead.
 */
final class HttpProbe
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly float $timeout = 8.0,
    ) {
    }

    /**
     * @return array{status: int, body: string, headers: array<string, string>}
     *         status 0 means the connection was refused or timed out, which
     *         some experiments deliberately provoke and must be able to tell
     *         apart from an answered 5xx
     */
    public function request(string $method, string $path, ?string $body = null): array
    {
        $options = [
            'http' => [
                'method' => $method,
                'header' => 'Content-Type: application/json',
                'content' => $body,
                'timeout' => $this->timeout,
                'ignore_errors' => true,
            ],
        ];

        // Cleared first so a request that gets no response at all can never
        // report the status of the previous one.
        http_clear_last_response_headers();
        $response = @file_get_contents($this->baseUrl . $path, false, stream_context_create($options));

        $status = 0;
        $headers = [];

        foreach (http_get_last_response_headers() ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $matches) === 1) {
                // A redirect chain yields several status lines; the last wins.
                $status = (int) $matches[1];

                continue;
            }

            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }
        }

        return ['status' => $status, 'body' => (string) $response, 'headers' => $headers];
    }

    /**
     * GET /metrics, parsed from its "name value" text lines.
     *
     * @return array<string, float> a non-numeric value reads as 0.0
     *
     * @throws RuntimeException the endpoint did not answer 200
     */
    public function metrics(): array
    {
        $response = $this->request('GET', '/metrics');

        if ($response['status'] !== 200) {
            throw new RuntimeException(sprintf('GET /metrics answered %d.', $response['status']));
        }

        $metrics = [];

        foreach (explode("\n", $response['body']) as $line) {
            if (preg_match('/^(\S+)\s+(\S+)$/', trim($line), $matches) === 1) {
                $metrics[$matches[1]] = is_numeric($matches[2]) ? (float) $matches[2] : 0.0;
            }
        }

        return $metrics;
    }
}
