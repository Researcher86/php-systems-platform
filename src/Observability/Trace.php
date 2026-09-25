<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Observability;

use JsonException;

/**
 * PLAN Step 24's small educational tracing system - a single request_id
 * carried through one HTTP request into the database calls it makes, the
 * queue job its write publishes (in the job's payload) and, on the worker,
 * the job's own execution. Explicitly not a full OpenTelemetry clone.
 *
 * A Trace is two things:
 *
 *  - a context: which request is running right now, activeRequestId().
 *    serve() begins one for every HTTP request it answers; a queue worker
 *    begins one for every job whose payload carries a request_id (the
 *    publish side put it there). Anything recorded while a request is
 *    active is correlated to that request.
 *  - a span journal: one entry per recorded operation - http.request,
 *    db.read / db.write, job.execute - each carrying the request_id it
 *    happened under, plus the span's own fields: job_id, worker_pid and
 *    attempt when the span IS a job execution, or the method/path/status
 *    of the HTTP answer.
 *
 * The four questions PLAN Step 24's final demo has to answer fall out of
 * those fields: a job's payload names the HTTP request that created it
 * (job.execute's request_id), the span names the process that ran it
 * (worker_pid), the duration spans time it, and each retry is its own
 * additional job.execute span under the same job_id.
 *
 * Spans are kept in memory for the process that recorded them and, when a
 * log path is given, appended to an append-only JSONL journal - the same
 * contract as the queue journal itself. That is what lets a worker's spans
 * outlive the worker and lets the `trace` CLI command read the whole chain
 * back. Without a journal the Trace is memory-only, which is exactly what
 * the unit tests use.
 *
 * Every seam is strictly opt-in (Application, Database, JobExecutor all
 * take the Trace as a nullable constructor argument): a service or command
 * that predates tracing runs precisely as it always did.
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

    /** @var list<array<string, mixed>> */
    private array $spans = [];

    private ?string $requestId = null;

    public function __construct(
        private readonly ?string $logPath = null,
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
     * Record one span for the active request. Without one nothing is
     * recorded at all - a span that cannot answer "which request?" is noise
     * a trace should not keep, and the boundary code stays one line.
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

        if ($this->logPath !== null) {
            $this->append($span);
        }
    }

    /**
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

            if (($span['request_id'] ?? null) === $requestId) {
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
