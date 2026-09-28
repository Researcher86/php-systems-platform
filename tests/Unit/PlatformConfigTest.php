<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * config/platform.php's own docblock promises that "an environment variable
 * ... is allowed to override" any default here - QUEUE_MAX_SIZE (PLAN
 * Step 17) is what lets `README`'s backpressure reproduction, and a scratch
 * `serve` in general, run with a small MAX_QUEUE_SIZE without editing the
 * file. Every other config value stays a plain default; this is the first
 * one the file itself reads from the environment. CACHE_ENABLED joined it in
 * Step 29, for the load test's "the read path without a cache tier".
 */
final class PlatformConfigTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('QUEUE_MAX_SIZE');
        putenv('PLATFORM_ENV');
        putenv('CACHE_ENABLED');
    }

    public function testQueueMaxSizeDefaultsToFiveHundred(): void
    {
        putenv('QUEUE_MAX_SIZE');

        $config = require dirname(__DIR__, 2) . '/config/platform.php';

        self::assertSame(500, $config['queue']['max_size']);
    }

    public function testQueueMaxSizeCanBeOverriddenByEnvironment(): void
    {
        putenv('QUEUE_MAX_SIZE=3');

        $config = require dirname(__DIR__, 2) . '/config/platform.php';

        self::assertSame(3, $config['queue']['max_size']);
    }

    public function testFailureInjectionIsArmedByDefault(): void
    {
        // PLAN Step 22: failure injection only in development/demo mode, and
        // "dev" is the platform's default environment - the lab defaults to
        // armed so the demos and debug endpoints work out of the box.
        putenv('PLATFORM_ENV');

        $config = require dirname(__DIR__, 2) . '/config/platform.php';

        self::assertTrue($config['failure_injection']['enabled']);
    }

    public function testFailureInjectionIsArmedInDemoAndTestEnvironments(): void
    {
        putenv('PLATFORM_ENV=demo');
        $config = require dirname(__DIR__, 2) . '/config/platform.php';
        self::assertTrue($config['failure_injection']['enabled']);

        putenv('PLATFORM_ENV=test');
        $config = require dirname(__DIR__, 2) . '/config/platform.php';
        self::assertTrue($config['failure_injection']['enabled']);
    }

    public function testFailureInjectionIsDisarmedOutsideDevelopmentEnvironments(): void
    {
        putenv('PLATFORM_ENV=production');

        $config = require dirname(__DIR__, 2) . '/config/platform.php';

        self::assertFalse($config['failure_injection']['enabled']);
    }

    /**
     * The '0' row is the reason this test exists at all. `getenv(...) ?: '1'`
     * is the obvious way to write a default for a missing variable, and PHP
     * considers the string '0' to be missing, so the one value the off
     * switch exists for was the one value it could not be set to - and the
     * platform quietly started a cache server for a run that had asked it
     * not to. The off switch is now spelled out in config/platform.php, and
     * this table is what keeps it that way.
     */
    public function testCacheEnabledFollowsTheEnvironment(): void
    {
        $cases = [
            '0' => false,
            ' 0 ' => false,
            'false' => false,
            'FALSE' => false,
            'no' => false,
            'off' => false,
            'OFF' => false,
            '1' => true,
            'on' => true,
            'perhaps' => true,
        ];

        foreach ($cases as $value => $expected) {
            putenv('CACHE_ENABLED=' . $value);

            $config = require dirname(__DIR__, 2) . '/config/platform.php';

            self::assertSame($expected, $config['cache']['enabled'], sprintf('CACHE_ENABLED=%s', $value));
        }
    }

    public function testCacheIsEnabledWhenTheEnvironmentIsSilent(): void
    {
        putenv('CACHE_ENABLED');

        $config = require dirname(__DIR__, 2) . '/config/platform.php';

        self::assertTrue($config['cache']['enabled']);
    }
}
