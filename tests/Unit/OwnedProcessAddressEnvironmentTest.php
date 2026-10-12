<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Tests\Unit;

use PhpSystemsPlatform\Support\OwnedProcess;
use PHPUnit\Framework\TestCase;

/**
 * A child started with a whole environment of its own (the experiments'
 * serve, an isolated benchmark pool) still has to find the platform where
 * this process found it: the ports, the pool socket and the temp directory
 * every data path lives under. addressEnvironment() is the part of this
 * process's environment that carries exactly that, and nothing else.
 */
final class OwnedProcessAddressEnvironmentTest extends TestCase
{
    private const array VARIABLES = ['HTTP_PORT', 'DATABASE_PORT', 'CACHE_PORT', 'WORKER_POOL_SOCKET', 'TMPDIR'];

    /** @var array<string, string|false> */
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (self::VARIABLES as $name) {
            $this->saved[$name] = getenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
    }

    public function testCarriesTheAddressesThisProcessWasGiven(): void
    {
        putenv('HTTP_PORT=18080');
        putenv('DATABASE_PORT=15433');
        putenv('CACHE_PORT=16380');
        putenv('WORKER_POOL_SOCKET=/run/pool.sock');
        putenv('TMPDIR=/var/scratch');

        self::assertSame([
            'HTTP_PORT' => '18080',
            'DATABASE_PORT' => '15433',
            'CACHE_PORT' => '16380',
            'WORKER_POOL_SOCKET' => '/run/pool.sock',
            'TMPDIR' => '/var/scratch',
        ], OwnedProcess::addressEnvironment());
    }

    public function testLeavesOutWhatWasNotSet(): void
    {
        foreach (self::VARIABLES as $name) {
            putenv($name);
        }

        putenv('CACHE_PORT=16380');

        self::assertSame(['CACHE_PORT' => '16380'], OwnedProcess::addressEnvironment());
    }

    public function testCarriesNothingElse(): void
    {
        foreach (self::VARIABLES as $name) {
            putenv($name);
        }

        putenv('QUEUE_MAX_SIZE=3');

        try {
            self::assertSame([], OwnedProcess::addressEnvironment());
        } finally {
            putenv('QUEUE_MAX_SIZE');
        }
    }
}
