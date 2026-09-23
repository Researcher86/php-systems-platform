<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Storage;

/**
 * The platform's schema, applied as part of `serve`'s startup (the DB server
 * starts with an empty data directory on a fresh checkout). Single-statement,
 * idempotent DDL only: the mini database's server opens exactly one database
 * per data directory, so a migration is a CREATE TABLE ... IF NOT EXISTS
 * rather than a versioned history.
 */
final class Migrator
{
    public static function migrate(Database $database): void
    {
        $database->write(
            <<<'SQL'
                CREATE TABLE IF NOT EXISTS orders (
                    id VARCHAR(36) PRIMARY KEY,
                    customer VARCHAR(255) NOT NULL,
                    amount DECIMAL(10,2) NOT NULL,
                    status VARCHAR(16) NOT NULL,
                    created_at VARCHAR(29) NOT NULL,
                    updated_at VARCHAR(29) NOT NULL
                )
                SQL,
        );
    }
}
