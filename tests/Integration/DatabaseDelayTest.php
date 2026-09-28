<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Tests\Integration;

use PhpSystemsPlatform\Storage\Database;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * PLAN Step 30's slow-database seam, tested against the real mini database
 * the way MigratorUpgradeTest does: the config can ask every read/write to
 * pay a delay, and Database::connect() is the one place that honors it. A
 * delayed read should take at least the configured delay and land in the
 * same data; a default connection should not pay a millisecond it did not
 * ask for.
 */
final class DatabaseDelayTest extends TestCase
{
    private const HOST = '127.0.0.1';
    private const PORT = 5456;
    private const DATA_DIR = '/tmp/php-systems-platform-delay';

    private Database $database;

    protected function setUp(): void
    {
        self::server('stop');
        exec(sprintf('rm -rf %s', escapeshellarg(self::DATA_DIR)));
        mkdir(self::DATA_DIR, 0o777, true);
        self::server('start');

        $this->database = Database::connect([
            'host' => self::HOST,
            'port' => self::PORT,
            'timeout' => 2.0,
            'delay_ms' => 300.0,
        ]);
    }

    protected function tearDown(): void
    {
        $this->database->close();
        self::server('stop');
    }

    public function testEveryReadPaysTheConfiguredDelay(): void
    {
        $startedAt = microtime(true);
        $rows = $this->database->read('SELECT 1 AS one');
        $elapsedMs = (microtime(true) - $startedAt) * 1000;

        self::assertSame(1, (int) ($rows[0]['one'] ?? 0));
        self::assertGreaterThanOrEqual(250, $elapsedMs, 'the configured 300ms delay should be observed');
        self::assertLessThan(2000, $elapsedMs, 'one delay, not several');
    }

    public function testEveryWritePaysTheConfiguredDelay(): void
    {
        $startedAt = microtime(true);
        $this->database->write('CREATE TABLE delay_test (id VARCHAR(64) PRIMARY KEY, note VARCHAR(255))');
        $this->database->write('INSERT INTO delay_test (id, note) VALUES (\'a\', \'hi\')');
        $elapsedMs = (microtime(true) - $startedAt) * 1000;

        self::assertGreaterThanOrEqual(500, $elapsedMs, 'two writes, each paying the delay');
        self::assertSame(1, (int) $this->database->read('SELECT COUNT(*) AS c FROM delay_test')[0]['c']);
    }

    public function testAConnectionWithoutADelayDoesNotPayOne(): void
    {
        $plain = Database::connect([
            'host' => self::HOST,
            'port' => self::PORT,
            'timeout' => 2.0,
        ]);

        try {
            $startedAt = microtime(true);
            $plain->read('SELECT 1 AS one');
            $elapsedMs = (microtime(true) - $startedAt) * 1000;

            self::assertLessThan(200, $elapsedMs, 'no configured delay means no delay paid');
        } finally {
            $plain->close();
        }
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
                '--pid-file',
                self::DATA_DIR . '/minidb.pid',
                '--log-file',
                self::DATA_DIR . '/minidb.log',
            );
        } elseif ($command === 'stop') {
            array_push($arguments, '--pid-file', self::DATA_DIR . '/minidb.pid');
        }

        exec(implode(' ', array_map('escapeshellarg', $arguments)));

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
