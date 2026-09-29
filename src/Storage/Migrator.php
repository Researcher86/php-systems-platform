<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Storage;

use PhpMiniDatabase\Client\ClientException;
use PhpMiniDatabase\Network\Protocol\ErrorCode;
use PhpSystemsPlatform\Domain\OrderService;
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
     * and would only break later, on the first insert. ALTER TABLE brings it
     * forward in place: the column is added (nullable, which is what an
     * added column can be for rows that already exist) and every order
     * written before it is backfilled with the default sku.
     *
     * Adding a column that is already there is the normal case - every
     * restart after the first - and this is the one failure to swallow.
     *
     * The match is on the message text, and deliberately not on the wire
     * error code. The server reports "Table \"orders\" already has a column
     * \"product\"." with code TABLE_NOT_FOUND - the same code a genuinely
     * missing table arrives with - so matching the code would also swallow a
     * real "table does not exist" and leave the platform running against no
     * schema at all. The message is the only signal that distinguishes them.
     * Anything else is a real problem and belongs to the caller.
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
     * The read and the insert are separate statements, so two processes
     * starting at the same moment - a serve and a queue consumer, both of
     * which migrate at startup - can both find the key missing and both
     * insert it. Whoever lost that race gets a primary-key violation, and
     * used to fail startup over a row that is present and correct. The
     * duplicate is the outcome the seeding wanted, so it is treated as
     * success; any other constraint failure still propagates.
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
     * Whether this is "that unique index already holds the value" and not
     * some other constraint failure.
     *
     * Both halves are required, and neither alone would do. The wire code
     * CONSTRAINT_VIOLATION is too broad: a NOT NULL violation and a foreign
     * key with no parent arrive with that same code, and treating either as
     * "already seeded" would quietly leave reference data unwritten. The
     * message alone is too loose across versions. Together they name exactly
     * the case where another process won the insert.
     */
    private static function isDuplicateKey(Throwable $e): bool
    {
        return $e instanceof ClientException
            && $e->errorCode === ErrorCode::CONSTRAINT_VIOLATION
            && str_contains($e->getMessage(), 'Duplicate value for unique index');
    }
}
