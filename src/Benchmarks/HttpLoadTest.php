<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Benchmarks;

use CurlHandle;
use RuntimeException;
use stdClass;

/**
 * Drives $requests HTTP requests at a fixed concurrency and keeps every
 * per-request time - PLAN Step 29's Tests A, B and C.
 *
 * The concurrency is real: one easy handle per in-flight slot, all driven by
 * one curl_multi handle, so $concurrency connections are open at once. Each
 * slot keeps its easy handle for the whole phase because curl keeps the
 * handle's connection cache across curl_reset(): a slot connects once, and
 * what gets measured is the server rather than a TCP handshake per request.
 *
 * A request that never completed still counts as a request and as a failure
 * but contributes no latency sample, so dropped connections cannot make a
 * phase look faster than it was.
 */
final class HttpLoadTest
{
    public const float DEFAULT_TIMEOUT_SECONDS = 10.0;

    public const float DEFAULT_CONNECT_TIMEOUT_SECONDS = 5.0;

    public function __construct(
        private readonly string $baseUrl,
        private readonly int $concurrency = 8,
        private readonly float $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
    ) {
    }

    /**
     * Run $requests requests over $paths, in order, cycling the list when it
     * is shorter than the request count: one path repeated measures a hot
     * entry point, one path per order a working set the cache has not seen.
     *
     * @param list<string>       $paths
     * @param array<string, string> $headers extra request header lines
     */
    public function run(
        string $label,
        array $paths,
        int $requests,
        string $method = 'GET',
        ?string $body = null,
        array $headers = [],
    ): HttpLoadResult {
        if ($requests < 1) {
            throw new RuntimeException('A load phase needs at least one request.');
        }

        if ($paths === []) {
            throw new RuntimeException('A load phase needs at least one path.');
        }

        $multi = curl_multi_init();
        $handles = [];
        $captures = [];
        $idle = [];
        $busy = [];
        $latencies = [];
        $statusCodes = [];
        $cacheTally = [];
        $next = 0;
        $completed = 0;

        $started = microtime(true);

        try {
            for ($slot = 0; $slot < $this->concurrency; $slot++) {
                $handle = curl_init();
                $handles[spl_object_id($handle)] = $handle;
                $captures[spl_object_id($handle)] = new stdClass();
                $captures[spl_object_id($handle)]->headers = '';
                $idle[] = spl_object_id($handle);
            }

            while ($completed < $requests) {
                while ($idle !== [] && $next < $requests) {
                    $id = array_shift($idle);
                    $this->prepare(
                        $handles[$id],
                        $captures[$id],
                        $paths[$next % count($paths)],
                        $method,
                        $body,
                        $headers,
                    );
                    curl_multi_add_handle($multi, $handles[$id]);
                    $busy[$id] = true;
                    $next++;
                }

                do {
                    $state = curl_multi_exec($multi, $active);
                } while ($state === CURLM_CALL_MULTI_PERFORM);

                while (($info = curl_multi_info_read($multi)) !== false) {
                    /** @var CurlHandle $handle */
                    $handle = $info['handle'];
                    $id = spl_object_id($handle);
                    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
                    $statusCodes[$status] = ($statusCodes[$status] ?? 0) + 1;

                    // Status 0 is a transport failure: no answer, so no
                    // latency to report and nothing counted as succeeded.
                    if ($status > 0) {
                        $latencies[] = round(
                            (float) curl_getinfo($handle, CURLINFO_TOTAL_TIME) * HttpLoadResult::MILLISECONDS,
                            2,
                        );
                    }

                    $marker = $this->xCacheValue($captures[$id]->headers);
                    $cacheTally[$marker] = ($cacheTally[$marker] ?? 0) + 1;

                    curl_multi_remove_handle($multi, $handle);
                    unset($busy[$id]);
                    $idle[] = $id;
                    $completed++;
                }

                if ($busy !== []) {
                    $this->waitForActivity($multi);
                }
            }
        } finally {
            // No curl_close(): it is a no-op since 8.0 and deprecated in 8.5.
            // The handles must still leave the multi handle, which would
            // otherwise keep them alive.
            foreach ($handles as $handle) {
                curl_multi_remove_handle($multi, $handle);
            }

            curl_multi_close($multi);
        }

        return HttpLoadResult::fromSamples(
            $label,
            $requests,
            $latencies,
            microtime(true) - $started,
            $statusCodes,
            $cacheTally,
        );
    }

    /**
     * @param CurlHandle           $handle
     * @param stdClass             $capture response-header sink for this slot
     * @param array<string, string> $headers
     */
    private function prepare(CurlHandle $handle, stdClass $capture, string $path, string $method, ?string $body, array $headers): void
    {
        $capture->headers = '';
        curl_reset($handle);

        curl_setopt_array($handle, [
            CURLOPT_URL => rtrim($this->baseUrl, '/') . $path,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => (int) ceil($this->timeoutSeconds),
            CURLOPT_CONNECTTIMEOUT => (int) ceil(self::DEFAULT_CONNECT_TIMEOUT_SECONDS),
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $this->headerLines($headers),
            // Captured only to read X-Cache from it.
            CURLOPT_HEADERFUNCTION => static function ($ignored, string $header) use ($capture): int {
                $capture->headers .= $header;

                return strlen($header);
            },
        ]);

        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }
    }

    /**
     * @param array<string, string> $headers
     *
     * @return list<string>
     */
    private function headerLines(array $headers): array
    {
        $lines = ['Accept: application/json'];

        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        return $lines;
    }

    /** The read path's own hit/miss marker, or "(none)" when the answer had none. */
    private function xCacheValue(string $headers): string
    {
        foreach (explode("\n", $headers) as $line) {
            if (stripos($line, 'X-Cache:') === 0) {
                return strtolower(trim(substr($line, strlen('X-Cache:'))));
            }
        }

        return '(none)';
    }

    /** Block until a transfer has activity, instead of spinning on curl_multi_exec(). */
    private function waitForActivity(\CurlMultiHandle $multi): void
    {
        // -1 means select had nothing to wait on; a short sleep stands in.
        if (curl_multi_select($multi, 0.05) === -1) {
            usleep(1_000);
        }
    }
}
