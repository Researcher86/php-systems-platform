<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Storage;

use PhpMiniDatabase\Client\ClientConfig;
use PhpMiniDatabase\Client\Connection;
use PhpMiniDatabase\Client\ConnectionPool;
use PhpSystemsPlatform\Observability\MetricsRegistry;
use PhpSystemsPlatform\Observability\Trace;

/**
 * The platform's one seam over php-mini-database: a small fixed-size
 * connection pool and two shaped operations (read rows, write rows). The SQL
 * stays in the repositories; the component's types never leak past here.
 *
 * Optional, wiring-decided extras:
 *  - a MetricsRegistry: db.operations, db.errors and db.operation_duration
 *    for every statement, failed ones included;
 *  - a Trace: a db.read / db.write span, correlated to whichever request
 *    is active (the Trace records nothing outside one);
 *  - a delay (the slow-database experiment, config `delay_ms`, which the
 *    config zeroes unless failure injection is on): paid by every statement
 *    INSIDE the timed region, so the metrics report what the caller waited.
 */
final readonly class Database
{
    /** The component's own default, for a config without read_timeout/write_timeout. */
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
     * Build straight from the `database` config block: host/port/timeout,
     * read_timeout/write_timeout and the optional delay_ms.
     *
     * @param array<string, mixed> $config
     */
    public static function connect(array $config, int $maxConnections = 10, ?MetricsRegistry $metrics = null, ?Trace $trace = null): self
    {
        $delayMs = max(0.0, (float) ($config['delay_ms'] ?? 0.0));

        return self::fromConfig(self::configFrom($config), $maxConnections, $metrics, $trace, $delayMs);
    }

    /**
     * The pure half of connect() - config array in, ClientConfig out, no
     * connection attempted - so the key mapping is testable on its own.
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
     * for a statement type that does not name one (DDL).
     *
     * @param list<mixed> $parameters
     */
    public function write(string $sql, array $parameters = []): ?int
    {
        /** @var int|null $affected */
        $affected = $this->run('db.write', static fn (Connection $c): mixed => $c->query($sql, $parameters)->affectedRows());

        return $affected;
    }

    public function close(): void
    {
        $this->pool->close();
    }

    /**
     * One statement: acquire a connection, run it, report the outcome once.
     *
     * Acquiring is inside the reported region: a refused connection or an
     * exhausted pool is exactly the database error db.errors must show. The
     * connection goes back in `finally` whatever happened, so a failing
     * query cannot leak a pool slot - which is how an outage under load
     * turns into a server that stops answering instead of one that errors.
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
        $connection = null;

        try {
            $this->slowDown();
            $connection = $this->pool->acquire();
            $result = $operation($connection);
        } catch (\Throwable $e) {
            $this->report($span, $startedAt, false);

            throw $e;
        } finally {
            if ($connection !== null) {
                $this->pool->release($connection);
            }
        }

        $this->report($span, $startedAt, true);

        return $result;
    }

    private function report(string $span, float $startedAt, bool $ok): void
    {
        $duration = microtime(true) - $startedAt;

        $this->metrics?->increment(MetricsRegistry::DB_OPERATIONS);

        if (!$ok) {
            $this->metrics?->increment(MetricsRegistry::DB_ERRORS);
        }

        $this->metrics?->observe(MetricsRegistry::DB_OPERATION_DURATION, $duration);
        $this->trace?->record($span, $duration, ['meta' => ['ok' => $ok]]);
    }

    private function slowDown(): void
    {
        if ($this->delayMs > 0.0) {
            usleep((int) round($this->delayMs * 1000.0));
        }
    }
}
