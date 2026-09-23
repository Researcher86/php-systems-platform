<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Storage;

use RuntimeException;
use Throwable;

/**
 * The platform's schema, applied as part of `serve`'s startup (the DB server
 * starts with an empty data directory on a fresh checkout). Single-statement,
 * idempotent DDL only: the mini database's server opens exactly one database
 * per data directory, so a migration is a CREATE TABLE ... IF NOT EXISTS
 * rather than a versioned history.
 *
 * Besides `orders` it owns the reference catalog - customers, products,
 * inventory - and seeds it. Those three tables are the platform's read-only
 * reference data: the concurrency phase loads them per order, and nothing in
 * the platform ever writes them, so seeding them here keeps the demo
 * self-contained instead of requiring a fixture step.
 */
final class Migrator
{
    /**
     * The seeded catalog, as `sku => [title, price, available, reserved]`.
     * OrderService::DEFAULT_PRODUCT is one of these keys, or every order
     * created without an explicit product would point at nothing.
     */
    private const array PRODUCTS = [
        'SKU-STANDARD' => ['Standard plan', '19.99', 120, 8],
        'SKU-PRO' => ['Pro plan', '49.00', 40, 12],
        'SKU-TEAM' => ['Team plan', '99.00', 5, 4],
    ];

    /**
     * @var array<string, array{string, string}> name => [tier, since]
     */
    private const array CUSTOMERS = [
        'Ada Lovelace' => ['gold', '1843-01-01'],
        'Grace Hopper' => ['silver', '1906-12-09'],
        'Alan Turing' => ['bronze', '1912-06-23'],
    ];

    public static function migrate(Database $database): void
    {
        self::createOrders($database);
        self::upgradeOrders($database);

        $database->write(
            <<<'SQL'
                CREATE TABLE IF NOT EXISTS customers (
                    name VARCHAR(255) PRIMARY KEY,
                    tier VARCHAR(16) NOT NULL,
                    since VARCHAR(29) NOT NULL
                )
                SQL,
        );

        $database->write(
            <<<'SQL'
                CREATE TABLE IF NOT EXISTS products (
                    sku VARCHAR(64) PRIMARY KEY,
                    title VARCHAR(255) NOT NULL,
                    price DECIMAL(10,2) NOT NULL
                )
                SQL,
        );

        $database->write(
            <<<'SQL'
                CREATE TABLE IF NOT EXISTS inventory (
                    sku VARCHAR(64) PRIMARY KEY,
                    available INT NOT NULL,
                    reserved INT NOT NULL
                )
                SQL,
        );

        self::seed($database);
    }

    private static function createOrders(Database $database): void
    {
        $database->write(
            <<<'SQL'
                CREATE TABLE IF NOT EXISTS orders (
                    id VARCHAR(36) PRIMARY KEY,
                    customer VARCHAR(255) NOT NULL,
                    amount DECIMAL(10,2) NOT NULL,
                    product VARCHAR(64) NOT NULL,
                    status VARCHAR(16) NOT NULL,
                    created_at VARCHAR(29) NOT NULL,
                    updated_at VARCHAR(29) NOT NULL
                )
                SQL,
        );
    }

    /**
     * CREATE TABLE IF NOT EXISTS leaves an existing table alone, so a data
     * directory written before orders carried a product keeps the old shape
     * and would only break later, on the first insert. The mini database
     * parses ALTER TABLE but does not execute it yet, so the column cannot be
     * added in place.
     *
     * The server validates a column only when a row is actually produced,
     * which makes one row the whole probe: an error means orders were written
     * under the old shape, and those are the caller's to keep or throw away -
     * the platform says so while it is still starting instead of failing on
     * the first write. No row means the table holds nothing, whichever shape
     * it has, so it is simply rebuilt in the current one.
     */
    private static function upgradeOrders(Database $database): void
    {
        try {
            if ($database->read('SELECT product FROM orders LIMIT 1') !== []) {
                return;
            }
        } catch (Throwable $e) {
            throw new RuntimeException(
                'The orders table was written before the product column existed and the mini database '
                . 'cannot add a column in place. Remove the database data directory and start again.',
                previous: $e,
            );
        }

        $database->write('DROP TABLE IF EXISTS orders');
        self::createOrders($database);
    }

    /**
     * Insert the reference rows that are missing. The mini database has no
     * upsert, so "seeded already" is a read: a key that answers stays
     * untouched, which keeps a second serve on the same data directory a
     * no-op instead of a primary-key error.
     */
    private static function seed(Database $database): void
    {
        foreach (self::CUSTOMERS as $name => [$tier, $since]) {
            if ($database->read('SELECT name FROM customers WHERE name = ?', [$name]) === []) {
                $database->write(
                    'INSERT INTO customers (name, tier, since) VALUES (?, ?, ?)',
                    [$name, $tier, $since],
                );
            }
        }

        foreach (self::PRODUCTS as $sku => [$title, $price, $available, $reserved]) {
            if ($database->read('SELECT sku FROM products WHERE sku = ?', [$sku]) === []) {
                $database->write(
                    'INSERT INTO products (sku, title, price) VALUES (?, ?, ?)',
                    [$sku, $title, $price],
                );
            }

            if ($database->read('SELECT sku FROM inventory WHERE sku = ?', [$sku]) === []) {
                $database->write(
                    'INSERT INTO inventory (sku, available, reserved) VALUES (?, ?, ?)',
                    [$sku, $available, $reserved],
                );
            }
        }
    }
}
