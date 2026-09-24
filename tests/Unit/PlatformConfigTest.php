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
 * one the file itself reads from the environment.
 */
final class PlatformConfigTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('QUEUE_MAX_SIZE');
        putenv('PLATFORM_ENV');
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
}
