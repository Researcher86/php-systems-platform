<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Storage;

use PhpMiniDatabase\Client\ClientConfig;
use PhpMiniDatabase\Client\ConnectionPool;
use PhpSystemsPlatform\Observability\MetricsRegistry;

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
    ) {
    }

    public static function fromConfig(ClientConfig $config, int $maxConnections = 10, ?MetricsRegistry $metrics = null): self
    {
        return new self(new ConnectionPool($config, $maxConnections), $metrics);
    }

    /**
     * The platform's usual way in: build straight from the `database`
     * config block (host/port/timeout, plus PLAN Step 18's read_timeout/
     * write_timeout - the database operation timeout) instead of every
     * caller repeating the same ClientConfig construction.
     *
     * @param array<string, mixed> $config
     */
    public static function connect(array $config, int $maxConnections = 10, ?MetricsRegistry $metrics = null): self
    {
        return self::fromConfig(self::configFrom($config), $maxConnections, $metrics);
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
        $startedAt = microtime(true);
        $connection = $this->pool->acquire();

        try {
            $rows = $connection->query($sql, $parameters)->fetchAll();
        } catch (\Throwable $e) {
            $this->metrics?->increment(MetricsRegistry::DB_OPERATIONS);
            $this->metrics?->increment(MetricsRegistry::DB_ERRORS);
            $this->metrics?->observe(MetricsRegistry::DB_OPERATION_DURATION, microtime(true) - $startedAt);
            throw $e;
        } finally {
            $this->pool->release($connection);
        }

        $this->metrics?->increment(MetricsRegistry::DB_OPERATIONS);
        $this->metrics?->observe(MetricsRegistry::DB_OPERATION_DURATION, microtime(true) - $startedAt);

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
        $startedAt = microtime(true);
        $connection = $this->pool->acquire();

        try {
            $affected = $connection->query($sql, $parameters)->affectedRows();
        } catch (\Throwable $e) {
            $this->metrics?->increment(MetricsRegistry::DB_OPERATIONS);
            $this->metrics?->increment(MetricsRegistry::DB_ERRORS);
            $this->metrics?->observe(MetricsRegistry::DB_OPERATION_DURATION, microtime(true) - $startedAt);
            throw $e;
        } finally {
            $this->pool->release($connection);
        }

        $this->metrics?->increment(MetricsRegistry::DB_OPERATIONS);
        $this->metrics?->observe(MetricsRegistry::DB_OPERATION_DURATION, microtime(true) - $startedAt);

        return $affected;
    }

    public function close(): void
    {
        $this->pool->close();
    }
}
