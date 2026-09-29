<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Storage;

use PhpMiniDatabase\Client\ClientException;
use PhpMiniDatabase\Network\Protocol\ErrorCode;
use PhpSystemsPlatform\Domain\OrderService;
use RuntimeException;
use Throwable;

/**
 * The platform's schema, applied at startup by every process that needs it.
 * Idempotent single-statement DDL (CREATE TABLE IF NOT EXISTS) rather than a
 * versioned history: the mini database holds one database per data dir.
 *
 * Besides `orders` it creates and seeds the reference catalog - customers,
 * products, inventory - so the demo needs no fixture step.
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
     * Bring a data dir created before orders had a product forward in place:
     * add the (nullable) column and backfill old rows with the default sku.
     *
     * "Column already exists" is the normal case on every restart and the one
     * failure swallowed. It is matched on the message, NOT the error code: the
     * server sends it as TABLE_NOT_FOUND, the same code a genuinely missing
     * table gets, and swallowing that would leave the platform schema-less.
     */
    private static function upgradeOrders(Database $database): void
    {
        try {
            $database->write('ALTER TABLE orders ADD COLUMN product VARCHAR(64)');
        } catch (Throwable $e) {
            if (!str_contains($e->getMessage(), 'already has a column')) {
                throw new RuntimeException('Could not add the product column to orders.', previous: $e);
            }

            return;
        }

        $database->write(
            'UPDATE orders SET product = ? WHERE product IS NULL',
            [OrderService::DEFAULT_PRODUCT],
        );
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
            self::insertIfMissing(
                $database,
                'SELECT name FROM customers WHERE name = ?',
                [$name],
                'INSERT INTO customers (name, tier, since) VALUES (?, ?, ?)',
                [$name, $tier, $since],
            );
        }

        foreach (self::PRODUCTS as $sku => [$title, $price, $available, $reserved]) {
            self::insertIfMissing(
                $database,
                'SELECT sku FROM products WHERE sku = ?',
                [$sku],
                'INSERT INTO products (sku, title, price) VALUES (?, ?, ?)',
                [$sku, $title, $price],
            );

            self::insertIfMissing(
                $database,
                'SELECT sku FROM inventory WHERE sku = ?',
                [$sku],
                'INSERT INTO inventory (sku, available, reserved) VALUES (?, ?, ?)',
                [$sku, $available, $reserved],
            );
        }
    }

    /**
     * Insert one reference row unless it is already there.
     *
     * Check-then-insert races when two processes migrate at the same moment
     * (a serve and a queue consumer): the loser gets a duplicate-key error
     * for a row that is present and correct, so that one error is success.
     *
     * @param list<mixed> $lookupParameters
     * @param list<mixed> $insertParameters
     */
    private static function insertIfMissing(
        Database $database,
        string $lookup,
        array $lookupParameters,
        string $insert,
        array $insertParameters,
    ): void {
        if ($database->read($lookup, $lookupParameters) !== []) {
            return;
        }

        try {
            $database->write($insert, $insertParameters);
        } catch (Throwable $e) {
            if (!self::isDuplicateKey($e)) {
                throw $e;
            }
        }
    }

    /**
     * Code AND message: CONSTRAINT_VIOLATION alone also covers NOT NULL and
     * foreign-key failures, which must not pass as "already seeded".
     */
    private static function isDuplicateKey(Throwable $e): bool
    {
        return $e instanceof ClientException
            && $e->errorCode === ErrorCode::CONSTRAINT_VIOLATION
            && str_contains($e->getMessage(), 'Duplicate value for unique index');
    }
}
