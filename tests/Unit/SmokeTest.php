<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Tests\Unit;

use PhpSystemsPlatform\Cli\PlatformCli;
use PHPUnit\Framework\TestCase;

final class SmokeTest extends TestCase
{
    public function testPlatformCliHubIsAutoloadable(): void
    {
        self::assertTrue(class_exists(PlatformCli::class));
    }
}
