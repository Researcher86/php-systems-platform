<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Tests\Integration;

use PhpMiniDatabase\Client\ClientConfig;
use PhpSystemsPlatform\Domain\OrderService;
use PhpSystemsPlatform\Storage\Database;
use PhpSystemsPlatform\Storage\Migrator;
use PhpSystemsPlatform\Storage\Repositories\OrderRepository;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The migration against a real database server of its own - a scratch data
 * directory on its own port, so the platform's own serve is never touched.
 *
 * Two properties matter. Applying the schema twice must change nothing (a
 * second serve on the same data directory is the normal case), and a data
 * directory written before the product column must be refused with an
 * explanation rather than left to fail later on the first insert: the mini
 * database parses ALTER TABLE but does not execute it yet, so the column
 * cannot be added in place.
 */
final class MigratorUpgradeTest extends TestCase
{
    private const HOST = '127.0.0.1';
    private const PORT = 5455;
    private const DATA_DIR = '/tmp/php-systems-platform-migrator';

    private Database $database;

    protected function setUp(): void
    {
        self::server('stop');
        exec(sprintf('rm -rf %s', escapeshellarg(self::DATA_DIR)));
        mkdir(self::DATA_DIR, 0o777, true);
        self::server('start');

        $this->database = Database::fromConfig(new ClientConfig(
            host: self::HOST,
            port: self::PORT,
            connectTimeoutSeconds: 2.0,
        ));
    }

    protected function tearDown(): void
    {
        $this->database->close();
        self::server('stop');
    }

    public function testApplyingTheSchemaTwiceLeavesOneSeededCatalog(): void
    {
        Migrator::migrate($this->database);
        Migrator::migrate($this->database);

        self::assertCount(3, $this->database->read('SELECT sku FROM products'));
        self::assertCount(3, $this->database->read('SELECT sku FROM inventory'));
        self::assertCount(3, $this->database->read('SELECT name FROM customers'));

        $orders = new OrderService(new OrderRepository($this->database));
        $order = $orders->createOrder('Ada Lovelace', '2.00', 'SKU-PRO');

        self::assertSame('SKU-PRO', $orders->getOrder($order->id)?->product);
    }

    public function testOrdersWrittenBeforeTheProductColumnAreRefusedWithAnExplanation(): void
    {
        $this->legacyOrders();
        $this->database->write(
            'INSERT INTO orders (id, customer, amount, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
            ['legacy-1', 'Ada Lovelace', '1.00', 'created', '2020-01-01T00:00:00Z', '2020-01-01T00:00:00Z'],
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('product');

        Migrator::migrate($this->database);
    }

    public function testAnEmptyOrdersTableFromBeforeTheProductColumnIsRebuilt(): void
    {
        // Nothing to lose, so the migration takes the table to the current
        // shape instead of refusing a directory that holds no orders.
        $this->legacyOrders();

        Migrator::migrate($this->database);

        $orders = new OrderService(new OrderRepository($this->database));
        $order = $orders->createOrder('Ada Lovelace', '2.00', 'SKU-PRO');

        self::assertSame('SKU-PRO', $orders->getOrder($order->id)?->product);
    }

    /**
     * The orders table as the platform wrote it before Step 13: no product.
     */
    private function legacyOrders(): void
    {
        $this->database->write(
            <<<'SQL'
                CREATE TABLE orders (
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

    private static function server(string $command): void
    {
        $arguments = [PHP_BINARY, dirname(__DIR__, 2) . '/bin/minidb.php', $command];

        if ($command === 'start') {
            array_push(
                $arguments,
                '--host',
                self::HOST,
                '--port',
                (string) self::PORT,
                '--data',
                self::DATA_DIR,
                '--daemon',
                '--log-file',
                self::DATA_DIR . '/minidb.log',
            );
        }

        array_push($arguments, '--pid-file', self::DATA_DIR . '/minidb.pid');

        $process = proc_open($arguments, [1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $pipes);

        if (is_resource($process)) {
            proc_close($process);
        }

        if ($command === 'start' && !self::portAnswers()) {
            throw new RuntimeException('The scratch database server did not start listening in time.');
        }
    }

    private static function portAnswers(float $timeoutSeconds = 10.0): bool
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            $socket = @stream_socket_client(sprintf('tcp://%s:%d', self::HOST, self::PORT), $code, $message, 0.2);

            if ($socket !== false) {
                fclose($socket);

                return true;
            }

            usleep(100_000);
        }

        return false;
    }
}
