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
}
