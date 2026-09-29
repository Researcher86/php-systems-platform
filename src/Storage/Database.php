<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Storage;

use PhpMiniDatabase\Client\ClientConfig;
use PhpMiniDatabase\Client\Connection;
use PhpMiniDatabase\Client\ConnectionPool;
use PhpSystemsPlatform\Observability\MetricsRegistry;
use PhpSystemsPlatform\Observability\Trace;

/**
 * The platform's one seam over php-mini-database: a small, fixed-size pool of
 * connections and two shaped operations (read rows, write rows). The pool
 * reconnects a stale connection between requests, which is all this wrapper
 * needs to care about - the SQL itself stays in the repositories, and the
 * component's types never leak past here.
 *
 * PLAN Step 23: with a MetricsRegistry attached, every read/write reports
 * db.operations, db.errors (a failed query still counts the operation) and
 * db.operation_duration, so the database load is observable the same way the
 * cache and HTTP paths are. Without one the wrapper behaves exactly as
 * before - observability is the server's wiring decision, never this class's.
 *
 * PLAN Step 24: the same opt-in seam carries a Trace. A read/write records
 * a db.read / db.write span - still correlated to the HTTP request that
 * issued it (serve opens the request scope around every answer, and a queue
 * worker re-opens it from a job's payload), because the trace only records
 * while a request is actually active. Every call keeps its exact behavior
 * without one.
 *
 * PLAN Step 30: the same seam carries a deliberate delay, off unless the
 * config asks for it. A platform that has only ever talked to a database
 * answering in microseconds has never seen what it does when the database
 * stops doing that - the latency lands in the request, the workers hold their
 * connections while they wait, and the queue behind them grows. That is
 * measurable but not demonstrable without a slow database, so the config can
 * ask for one. It is a fault, not a setting, which is why the delay is only
 * honored where failure injection is on at all (dev/demo/test, see
 * config/platform.php) and only when it is asked for by name.
 *
 * The delay sits inside the timed region rather than around it, so
 * db.operation_duration reports what the caller actually waited - the point
 * of the experiment is to watch the platform react to slow I/O, and a
 * measurement that excluded the slowness would be measuring nothing.
 */
final readonly class Database
{
    /** The component's own default - kept here so a config without
     *  read_timeout/write_timeout gets exactly what it would have gotten
     *  before those keys existed, not a silent zero. */
    private const float DEFAULT_OPERATION_TIMEOUT = 30.0;

    public function __construct(
        private ConnectionPool $pool,
        private ?MetricsRegistry $metrics = null,
        private ?Trace $trace = null,
        private float $delayMs = 0.0,
    ) {
    }

    public static function fromConfig(ClientConfig $config, int $maxConnections = 10, ?MetricsRegistry $metrics = null, ?Trace $trace = null, float $delayMs = 0.0): self
    {
        return new self(new ConnectionPool($config, $maxConnections), $metrics, $trace, $delayMs);
    }

    /**
     * PLAN Step 30's slow-database experiment: a whole-platform delay, off by
     * default, that every read and every write pays. connect() is the only
     * caller that can be asked for it - fromConfig() is the pure config
     * mapping and deliberately does not take it, so the shape of a ClientConfig
     * stays the component's own.
     *
     * @param array<string, mixed> $config
     */
    private static function delayFrom(array $config): float
    {
        $delay = (float) ($config['delay_ms'] ?? 0.0);

        return $delay > 0.0 ? $delay : 0.0;
    }

    /**
     * The platform's usual way in: build straight from the `database`
     * config block (host/port/timeout, plus PLAN Step 18's read_timeout/
     * write_timeout - the database operation timeout) instead of every
     * caller repeating the same ClientConfig construction.
     *
     * @param array<string, mixed> $config
     */
    public static function connect(array $config, int $maxConnections = 10, ?MetricsRegistry $metrics = null, ?Trace $trace = null): self
    {
        return self::fromConfig(self::configFrom($config), $maxConnections, $metrics, $trace, self::delayFrom($config));
    }

    /**
     * The pure half of connect() - config array in, component ClientConfig
     * out, no connection attempted. Kept separate so the mapping (which
     * keys mean what, what a missing one defaults to) is testable on its
     * own.
     *
     * @param array<string, mixed> $config
     */
    public static function configFrom(array $config): ClientConfig
    {
        return new ClientConfig(
            host: (string) $config['host'],
            port: (int) $config['port'],
            connectTimeoutSeconds: (float) $config['timeout'],
            readTimeoutSeconds: (float) ($config['read_timeout'] ?? self::DEFAULT_OPERATION_TIMEOUT),
            writeTimeoutSeconds: (float) ($config['write_timeout'] ?? self::DEFAULT_OPERATION_TIMEOUT),
        );
    }

    /**
     * Run a SELECT and return every row as an associative array.
     *
     * @param list<mixed> $parameters
     *
     * @return list<array<string, mixed>>
     */
    public function read(string $sql, array $parameters = []): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->run('db.read', static fn (Connection $c): mixed => $c->query($sql, $parameters)->fetchAll());

        return $rows;
    }

    /**
     * Run an INSERT/UPDATE/DELETE. Returns the affected row count, or null
     * for a statement type that does not name one (DDL such as the
     * migration).
     *
     * @param list<mixed> $parameters
     */
    public function write(string $sql, array $parameters = []): ?int
    {
        /** @var int|null $affected */
        $affected = $this->run('db.write', static fn (Connection $c): mixed => $c->query($sql, $parameters)->affectedRows());

        return $affected;
    }

    /**
     * One statement: acquire a connection, run it, and report the outcome -
     * the same four bookkeeping steps whichever way it went.
     *
     * read() and write() used to carry this between them, twice each, and the
     * duplication was not cosmetic: the failure path records its own
     * duration and then rethrows, so any change to how an operation is
     * reported had to be made in four places, and a missed one is a span that
     * reports the wrong outcome while the metrics report the right one. The
     * report is here once, with one success path and one failure path.
     *
     * The connection is released in `finally` whether the statement succeeded,
     * failed or threw, so a failing query cannot leak a pool slot - which is
     * how a database outage under load turns into a server that stops
     * answering rather than one that reports errors.
     *
     * @template T
     *
     * @param callable(Connection): T $operation
     *
     * @return T
     */
    private function run(string $span, callable $operation): mixed
    {
        $startedAt = microtime(true);
        $this->slowDown();
        $connection = $this->pool->acquire();

        try {
            $result = $operation($connection);
        } catch (\Throwable $e) {
            $this->report($span, $startedAt, false);

            throw $e;
        } finally {
            $this->pool->release($connection);
        }

        $this->report($span, $startedAt, true);

        return $result;
    }

    /**
     * Count the operation, note its duration, and record the span - the same
     * three things for every statement, in one place.
     */
    private function report(string $span, float $startedAt, bool $ok): void
    {
        $this->metrics?->increment(MetricsRegistry::DB_OPERATIONS);

        if (!$ok) {
            $this->metrics?->increment(MetricsRegistry::DB_ERRORS);
        }

        $this->metrics?->observe(MetricsRegistry::DB_OPERATION_DURATION, microtime(true) - $startedAt);
        $this->trace?->record($span, microtime(true) - $startedAt, ['meta' => ['ok' => $ok]]);
    }

    public function close(): void
    {
        $this->pool->close();
    }

    private function slowDown(): void
    {
        if ($this->delayMs <= 0.0) {
            return;
        }

        usleep((int) round($this->delayMs * 1000.0));
    }
}
