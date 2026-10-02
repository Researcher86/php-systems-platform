<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Observability;

use JsonException;

/**
 * A small educational tracer (explicitly not an OpenTelemetry clone): one
 * request_id carried from an HTTP request into its database calls, the job
 * its write publishes (in the payload) and that job's execution on a worker.
 *
 * A Trace is two things:
 *
 *  - a context: the request running right now, activeRequestId(). The
 *    Application opens one per HTTP request; a queue worker re-opens one
 *    for each job whose payload carries a request_id.
 *  - a span journal: one entry per operation (http.request, db.read,
 *    db.write, job.execute) with the request_id it ran under plus its own
 *    fields - job_id/worker_pid/attempt for a job, method/path/status for
 *    an HTTP answer. Each retry is its own job.execute span.
 *
 * Spans stay in a bounded in-memory window and, with a log path, are also
 * appended to a JSONL journal, which is what lets a worker's spans outlive
 * the worker and the `trace` CLI read a whole chain back. Every seam takes
 * the Trace as nullable: without one, nothing is traced.
 */
final class Trace
{
    /**
     * Inbound request ids must look like ids, not like payload: 8-128
     * URL- and header-safe characters. Anything else is replaced with a
     * fresh one - a caller can neither squash the id space nor smuggle new
     * lines into the journal this way.
     */
    private const string INBOUND_PATTERN = '/^[A-Za-z0-9._-]{8,128}$/';

    /**
     * The in-memory window. The journal is the durable record, so the oldest
     * span is the one to drop - readLog() still finds it - and a serve that
     * runs for days does not grow without bound.
     */
    private const int DEFAULT_MEMORY_SPANS = 1000;

    /** @var list<array<string, mixed>> */
    private array $spans = [];

    private ?string $requestId = null;

    public function __construct(
        private readonly ?string $logPath = null,
        private readonly int $maxSpans = self::DEFAULT_MEMORY_SPANS,
    ) {
    }

    /**
     * Open a request scope. A well-formed inbound X-Request-ID is kept, so
     * a client may carry its chain across the whole system (and the worker
     * re-opens the same scope from a job payload); anything absent or
     * malformed gets a fresh id. The id stays active until finishRequest(),
     * correlating every record() in between to this one request.
     */
    public function beginRequest(?string $inbound): string
    {
        $this->requestId = $this->normalize($inbound);

        return $this->requestId;
    }

    public function finishRequest(): void
    {
        $this->requestId = null;
    }

    public function activeRequestId(): ?string
    {
        return $this->requestId;
    }

    /**
     * Record one span for the active request. Outside a request nothing is
     * recorded - a span that cannot answer "which request?" is noise.
     *
     * @param array<string, mixed> $extra span fields besides the common
     *                                    request_id/started_at/duration trio
     *                                    (job_id, worker_pid, attempt, meta); null
     *                                    values are dropped so the journal only
     *                                    ever holds fields that mean something
     */
    public function record(string $operation, float $duration, array $extra = []): void
    {
        if ($this->requestId === null) {
            return;
        }

        $span = [
            'operation' => $operation,
            'request_id' => $this->requestId,
            'started_at' => round(microtime(true) - $duration, 6),
            'duration' => round($duration, 6),
        ] + $this->withoutNulls($extra);

        $this->spans[] = $span;

        if (count($this->spans) > $this->maxSpans) {
            // Once full, every record drops the oldest span, so the window
            // is exactly the newest maxSpans. array_shift() reindexes the
            // buffer each time - O(maxSpans), microseconds at the default
            // size, and simpler than a ring buffer.
            array_shift($this->spans);
        }

        if ($this->logPath !== null) {
            $this->append($span);
        }
    }

    /**
     * The spans this process still holds, newest-last. Bounded by the
     * constructor's $maxSpans - pass a request id to get only that request's
     * spans, and expect a request older than the window to be absent here
     * while still being readable from the journal.
     *
     * @return list<array<string, mixed>>
     */
    public function spans(?string $requestId = null): array
    {
        if ($requestId === null) {
            return $this->spans;
        }

        return array_values(array_filter(
            $this->spans,
            static fn (array $span): bool => $span['request_id'] === $requestId,
        ));
    }

    /**
     * Read the full chain for one request back from the journal: the serve
     * process's request and database spans plus every worker's job.execute
     * span under the same request_id, no matter which process recorded
     * them. Empty without a journal or with nothing recorded for the id.
     *
     * @return list<array<string, mixed>>
     */
    public function readLog(string $requestId): array
    {
        if ($this->logPath === null || !is_file($this->logPath)) {
            return [];
        }

        $traces = [];

        foreach (file($this->logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            try {
                $span = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                // A torn tail line from a concurrent append - not this
                // request, and not worth failing the read over.
                continue;
            }

            if (is_array($span) && ($span['request_id'] ?? null) === $requestId) {
                $traces[] = $span;
            }
        }

        return $traces;
    }

    /** @param array<string, mixed> $span */
    private function append(array $span): void
    {
        $json = json_encode($span, JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            return;
        }

        @file_put_contents($this->logPath, $json . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function withoutNulls(array $extra): array
    {
        return array_filter(
            $extra,
            static fn (mixed $value): bool => $value !== null,
        );
    }

    private function normalize(?string $inbound): string
    {
        if (is_string($inbound) && preg_match(self::INBOUND_PATTERN, $inbound) === 1) {
            return $inbound;
        }

        return 'req-' . bin2hex(random_bytes(8));
    }
}
