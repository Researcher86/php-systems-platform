<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Support;

/**
 * A minimal HTTP client for commands that drive a platform by hand instead of
 * benchmarking it: the failure experiments need a request's status line, its
 * headers (X-Cache: hit or miss, Retry-After) and its body, and nothing else.
 * The load tests use curl through HttpLoadTest; this is the lighter seam for
 * reads that mostly want to observe the platform's own counters afterwards.
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
     *         some experiments are deliberately asking for and must be able
     *         to distinguish from an answered 5xx
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

        $response = @file_get_contents($this->baseUrl . $path, false, stream_context_create($options));

        $status = 0;
        $headers = [];

        foreach ($http_response_header as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $matches) === 1) {
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
}
