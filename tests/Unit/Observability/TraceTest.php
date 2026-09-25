<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Tests\Unit\Observability;

use PhpSystemsPlatform\Observability\Trace;
use PHPUnit\Framework\TestCase;

/**
 * PLAN Step 24's tracer, on its own: request id resolution (kept/generated),
 * active-request scoping and the span journal - in memory for the recording
 * process, and appended to the JSONL log when one is configured so another
 * process (a worker, the `trace` CLI) can read the chain back.
 */
final class TraceTest extends TestCase
{
    private const string TRACE_LOG = '/tmp/php-systems-platform/tests/trace.log';

    protected function setUp(): void
    {
        @unlink(self::TRACE_LOG);
        @mkdir(dirname(self::TRACE_LOG), 0o777, true);
    }

    protected function tearDown(): void
    {
        @unlink(self::TRACE_LOG);
    }

    public function testGeneratesARequestIdWhenNoInboundHeaderArrives(): void
    {
        $trace = new Trace();

        $requestId = $trace->beginRequest(null);

        self::assertMatchesRegularExpression('/^req-[a-f0-9]{16}$/', $requestId);
    }

    public function testKeepsAWellFormedInboundRequestId(): void
    {
        $trace = new Trace();

        self::assertSame('req-0102030405060708', $trace->beginRequest('req-0102030405060708'));
    }

    public function testMalformedInboundRequestIdFallsBackToAGeneratedOne(): void
    {
        $trace = new Trace();

        $requestId = $trace->beginRequest('not an id at all');

        self::assertMatchesRegularExpression('/^req-[a-f0-9]{16}$/', $requestId);
        self::assertNotSame('not an id at all', $requestId);
    }

    public function testRecordProducesASpanCorrelatedToTheActiveRequest(): void
    {
        $trace = new Trace();
        $requestId = $trace->beginRequest('req-0102030405060708');

        $trace->record('job.execute', 0.125, [
            'job_id' => 'job-123',
            'worker_pid' => 4321,
            'attempt' => 2,
            'meta' => ['outcome' => 'completed'],
        ]);

        $spans = $trace->spans();

        self::assertCount(1, $spans);

        $span = $spans[0];

        self::assertSame('job.execute', $span['operation']);
        self::assertSame($requestId, $span['request_id']);
        self::assertSame('job-123', $span['job_id']);
        self::assertSame(4321, $span['worker_pid']);
        self::assertSame(2, $span['attempt']);
        self::assertSame(['outcome' => 'completed'], $span['meta']);
        self::assertSame(0.125, $span['duration']);
    }

    public function testRecordWithoutAnActiveRequestIsSkipped(): void
    {
        $trace = new Trace();

        $trace->record('http.request', 1.0);

        self::assertSame([], $trace->spans());
    }

    public function testFinishRequestEndsTheScope(): void
    {
        $trace = new Trace();
        $trace->beginRequest('req-0102030405060708');
        $trace->record('db.read', 0.01);
        $trace->finishRequest();
        $trace->record('http.request', 0.02);

        self::assertCount(1, $trace->spans());
        self::assertSame('db.read', $trace->spans()[0]['operation']);
    }

    public function testSpansFilterByRequestId(): void
    {
        $trace = new Trace();

        foreach (['req-0000000000000001', 'req-0000000000000002'] as $id) {
            $trace->beginRequest($id);
            $trace->record('http.request', 0.01);
            $trace->finishRequest();
        }

        $filtered = $trace->spans('req-0000000000000002');

        self::assertCount(1, $filtered);
        self::assertSame('req-0000000000000002', $filtered[0]['request_id']);
    }

    public function testJournalAppendsSpansAndReadsTheChainBack(): void
    {
        $trace = new Trace(self::TRACE_LOG);

        $requestId = $trace->beginRequest('req-0102030405060708');
        $trace->record('http.request', 0.005);
        $trace->record('db.write', 0.001);
        $trace->finishRequest();

        // A second process (a worker, say) appended this span for the same
        // request - readLog() must see both halves of the chain.
        file_put_contents(
            self::TRACE_LOG,
            json_encode(['operation' => 'job.execute', 'request_id' => $requestId, 'job_id' => 'job-1', 'started_at' => 123.0, 'duration' => 0.02], JSON_THROW_ON_ERROR) . PHP_EOL,
            FILE_APPEND,
        );

        $chain = $trace->readLog($requestId);

        self::assertCount(3, $chain);
        self::assertSame(['http.request', 'db.write', 'job.execute'], array_column($chain, 'operation'));

        // A request id with no spans reads back as an empty chain.
        self::assertSame([], $trace->readLog('req-ffffffffffffffff'));
    }
}
