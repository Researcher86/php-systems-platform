<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * `trace` prints PLAN Step 24's chain for one request_id from the same JSONL
 * journal serve and every pool worker append to - the read side of the
 * spans the ServeIntegrationTest writes end to end. Like the other
 * demo/status commands it runs as a subprocess against the real config's
 * trace_store.
 */
final class TraceCommandTest extends TestCase
{
    private const TRACE_LOG = '/tmp/php-systems-platform/trace.log';

    public function testPrintsTheChainForOneRequestId(): void
    {
        $this->seedTraceLog();

        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/bin/platform.php', 'trace', 'req-0102030405060708'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        self::assertIsResource($process);

        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        @unlink(self::TRACE_LOG);

        self::assertSame(0, $code, $errors . $output);

        // Every span of the request's chain, in journal order, and only its
        // own: the http.request span (with route and status), the database
        // spans, and the worker's job.execute span with the full provenance.
        self::assertStringContainsString('http.request', (string) $output);
        self::assertStringContainsString('POST /orders -> 201', (string) $output);
        self::assertStringContainsString('db.write', (string) $output);
        self::assertStringContainsString('job=', (string) $output);
        self::assertStringContainsString('worker_pid=', (string) $output);
        self::assertStringContainsString('attempt=1', (string) $output);
        self::assertStringContainsString('completed', (string) $output);
        self::assertStringContainsString('req-0102030405060708', (string) $output);
        self::assertStringNotContainsString('req-aaaaaaaaaaaaaaaa', (string) $output);
    }

    public function testUnknownRequestIdReportsAnEmptyChainAndSucceeds(): void
    {
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/bin/platform.php', 'trace', 'req-ffffffffffffffff'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        self::assertIsResource($process);

        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        self::assertSame(0, $code);
        self::assertSame("No spans recorded for req-ffffffffffffffff.\n", $output);
    }

    public function testMissingRequestIdIsAUsageError(): void
    {
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/bin/platform.php', 'trace'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        self::assertIsResource($process);

        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        self::assertSame(1, $code);
        self::assertSame('', $output);
        self::assertStringContainsString('usage: php bin/platform.php trace <request_id>', (string) $errors);
    }

    private function seedTraceLog(): void
    {
        @mkdir(dirname(self::TRACE_LOG), 0o777, true);
        @unlink(self::TRACE_LOG);

        $spans = [
            [
                'operation' => 'http.request',
                'request_id' => 'req-0102030405060708',
                'started_at' => 1000.0,
                'duration' => 0.004,
                'meta' => ['method' => 'POST', 'path' => '/orders', 'status' => 201],
            ],
            [
                'operation' => 'db.write',
                'request_id' => 'req-0102030405060708',
                'started_at' => 1000.001,
                'duration' => 0.0005,
                'meta' => ['ok' => true],
            ],
            [
                'operation' => 'job.execute',
                'request_id' => 'req-0102030405060708',
                'job_id' => 'job-1',
                'worker_pid' => 4242,
                'attempt' => 1,
                'started_at' => 1000.02,
                'duration' => 0.03,
                'meta' => ['outcome' => 'completed'],
            ],
            // Someone else's request - must stay out of the chain.
            [
                'operation' => 'http.request',
                'request_id' => 'req-aaaaaaaaaaaaaaaa',
                'started_at' => 999.0,
                'duration' => 0.01,
                'meta' => ['method' => 'GET', 'path' => '/health', 'status' => 200],
            ],
        ];

        $lines = array_map(
            static fn (array $span): string => json_encode($span, JSON_THROW_ON_ERROR),
            $spans,
        );

        file_put_contents(self::TRACE_LOG, implode(PHP_EOL, $lines) . PHP_EOL);
    }
}
