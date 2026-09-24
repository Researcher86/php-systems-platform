<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Tests\Unit;

use PhpSystemsPlatform\Storage\Database;
use PHPUnit\Framework\TestCase;

/**
 * PLAN Step 18's database operation timeout: read/write timeouts the
 * platform's own config now names explicitly, instead of every caller
 * silently inheriting the component's ClientConfig defaults (30s each).
 * Database::configFrom() is the pure mapping - the one place that decides
 * how a platform config array becomes a component ClientConfig - so it is
 * tested directly, without a database connection.
 */
final class DatabaseConfigTest extends TestCase
{
    public function testMapsHostPortAndEveryTimeout(): void
    {
        $config = Database::configFrom([
            'host' => '10.0.0.5',
            'port' => 5555,
            'timeout' => 1.5,
            'read_timeout' => 7.0,
            'write_timeout' => 9.0,
        ]);

        self::assertSame('10.0.0.5', $config->host);
        self::assertSame(5555, $config->port);
        self::assertSame(1.5, $config->connectTimeoutSeconds);
        self::assertSame(7.0, $config->readTimeoutSeconds);
        self::assertSame(9.0, $config->writeTimeoutSeconds);
    }

    public function testReadAndWriteTimeoutFallBackToTheComponentDefaultWhenNotConfigured(): void
    {
        $config = Database::configFrom([
            'host' => '127.0.0.1',
            'port' => 5433,
            'timeout' => 2.0,
        ]);

        self::assertSame(30.0, $config->readTimeoutSeconds);
        self::assertSame(30.0, $config->writeTimeoutSeconds);
    }
}
