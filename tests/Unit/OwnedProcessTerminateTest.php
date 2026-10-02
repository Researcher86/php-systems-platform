<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Tests\Unit;

use PhpSystemsPlatform\Support\OwnedProcess;
use PHPUnit\Framework\TestCase;

/**
 * OwnedProcess::terminate() against children that do not stop on the first
 * SIGTERM - the failure that used to hang a caller in proc_close() forever.
 */
final class OwnedProcessTerminateTest extends TestCase
{
    public function testASigtermThatGoesUnansweredIsSentAgain(): void
    {
        // Swallows the first SIGTERM, exits on the second.
        $process = $this->spawn(<<<'PHP'
            pcntl_async_signals(true);
            $seen = 0;
            pcntl_signal(SIGTERM, function () use (&$seen): void { $seen++; });
            echo "ready\n";
            while ($seen < 2) { usleep(10_000); }
            PHP);

        $started = microtime(true);
        $exited = OwnedProcess::terminate($process, 10.0);

        self::assertTrue($exited);
        self::assertLessThan(8.0, microtime(true) - $started);
        self::assertSame(0, proc_close($process));
    }

    public function testAChildThatNeverStopsOnSigtermIsKilledAtTheDeadline(): void
    {
        $process = $this->spawn(<<<'PHP'
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, static function (): void {});
            echo "ready\n";
            while (true) { usleep(10_000); }
            PHP);

        $started = microtime(true);
        $exited = OwnedProcess::terminate($process, 1.0);

        self::assertFalse($exited);
        self::assertLessThan(5.0, microtime(true) - $started);
        // SIGKILL is not instant: the kernel still has to tear the process down.
        self::assertTrue(OwnedProcess::waitForQuietly(static fn (): bool => !proc_get_status($process)['running'], 2.0));
        proc_close($process);
    }

    /** @return resource a child that has already installed its SIGTERM handler */
    private function spawn(string $code): mixed
    {
        $process = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);

        // Only signal it once the handler is in place: before that, SIGTERM
        // would simply kill it and prove nothing.
        self::assertSame("ready\n", fgets($pipes[1]));
        fclose($pipes[1]);

        return $process;
    }
}
