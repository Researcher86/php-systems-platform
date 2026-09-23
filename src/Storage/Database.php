<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Storage;

use PhpMiniDatabase\Client\ClientConfig;
use PhpMiniDatabase\Client\ConnectionPool;

/**
 * The platform's one seam over php-mini-database: a small, fixed-size pool of
 * connections and two shaped operations (read rows, write rows). The pool
 * reconnects a stale connection between requests, which is all this wrapper
 * needs to care about - the SQL itself stays in the repositories, and the
 * component's types never leak past here.
 */
final readonly class Database
{
    public function __construct(
        private ConnectionPool $pool,
    ) {
    }

    public static function fromConfig(ClientConfig $config, int $maxConnections = 10): self
    {
        return new self(new ConnectionPool($config, $maxConnections));
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
        $connection = $this->pool->acquire();

        try {
            return $connection->query($sql, $parameters)->fetchAll();
        } finally {
            $this->pool->release($connection);
        }
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
        $connection = $this->pool->acquire();

        try {
            return $connection->query($sql, $parameters)->affectedRows();
        } finally {
            $this->pool->release($connection);
        }
    }

    public function close(): void
    {
        $this->pool->close();
    }
}
