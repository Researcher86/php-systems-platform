<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Tests\Unit\Memory;

use PhpSystemsPlatform\Memory\ProcStatusReader;
use PHPUnit\Framework\TestCase;

/**
 * The parse half is pure and tested against a fixture, the same way
 * php-memory-lab's own reader is tested - a real /proc/<pid>/status line
 * shape without needing a live process to produce one.
 */
final class ProcStatusReaderTest extends TestCase
{
    public function testKbFieldsAreConvertedToBytes(): void
    {
        $fields = new ProcStatusReader()->parse(<<<STATUS
            Name:\tphp
            Pid:\t42
            VmSize:\t    2584 kB
            VmRSS:\t    1200 kB
            RssAnon:\t      96 kB
            RssShmem:\t       0 kB
            Threads:\t1
            STATUS);

        self::assertSame('php', $fields['Name']);
        self::assertSame(42, $fields['Pid']);
        self::assertSame(2584 * 1024, $fields['VmSize']);
        self::assertSame(1200 * 1024, $fields['VmRSS']);
        self::assertSame(96 * 1024, $fields['RssAnon']);
        self::assertSame(0, $fields['RssShmem']);
        self::assertSame(1, $fields['Threads']);
    }

    public function testReadingTheCurrentProcessFindsARealRss(): void
    {
        $fields = new ProcStatusReader()->read();

        self::assertArrayHasKey('VmRSS', $fields);
        self::assertIsInt($fields['VmRSS']);
        self::assertGreaterThan(0, $fields['VmRSS']);
    }
}
