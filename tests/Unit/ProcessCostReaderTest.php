<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Tests\Unit;

use PhpSystemsPlatform\Benchmarks\ProcessCost;
use PhpSystemsPlatform\Benchmarks\ProcessCostReader;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Reading a live process's cost out of /proc - the CPU and memory PLAN
 * Step 29's Test A reports.
 *
 * The reader is pointed at this test process, which is the only pid a test
 * can be certain exists: it is making the assertions. What is being pinned
 * here is the shape of the answer and the two ways it is allowed to refuse -
 * a process that is gone, and a pid that was never real. Both must be
 * errors. A load test that reported "0.0 CPU seconds" for a serve it had
 * failed to find would report its own bug as a result.
 */
final class ProcessCostReaderTest extends TestCase
{
    private ProcessCostReader $reader;

    protected function setUp(): void
    {
        if (!is_readable('/proc/self/stat')) {
            self::markTestSkipped('Process costs are read from /proc, which this host does not have.');
        }

        $this->reader = new ProcessCostReader();
    }

    public function testItReadsThisProcess(): void
    {
        $cost = $this->reader->read(getmypid());

        self::assertSame(getmypid(), $cost->pid);
        self::assertGreaterThan(0.0, $cost->cpuSeconds, 'a running process has spent some CPU on itself');
        self::assertGreaterThan(0, $cost->rssBytes);
    }

    public function testTheKernelPeakIsAtLeastTheCurrentResidentSize(): void
    {
        $cost = $this->reader->read(getmypid());

        // VmHWM is a high-water mark, so it can be much larger than VmRSS
        // after a spike - but never smaller. A reader that mixed the two up
        // would report a peak under the current size, which is not a
        // measurement, it is a contradiction.
        self::assertGreaterThanOrEqual($cost->rssBytes, $cost->peakRssBytes);
    }

    public function testCpuIsReadAsADeltaBetweenTwoReadings(): void
    {
        $before = $this->reader->read(getmypid());

        // Spin for a fixed stretch of wall time rather than a fixed number of
        // iterations: CPU here is accounted in 10ms ticks, so the work has to
        // be comfortably longer than one tick to be visible at all, and
        // iterations-per-millisecond differ by an order of magnitude between
        // a laptop and a CI runner.
        $until = microtime(true) + 0.25;
        $burn = 0;

        while (microtime(true) < $until) {
            for ($i = 0; $i < 10_000; $i++) {
                $burn += $i;
            }
        }

        $after = $this->reader->read(getmypid());

        unset($burn);

        self::assertGreaterThan(0.0, $after->cpuSince($before), 'a busy process costs CPU between two readings');
        self::assertGreaterThanOrEqual(0.0, $after->cpuSince($after), 'a reading is never behind itself');
        self::assertSame($after->cpuSeconds, $after->cpuSince(new ProcessCost($after->pid, 0.0, 0, 0)));
    }

    public function testAProcessThatIsNotThereIsAnError(): void
    {
        $this->expectException(RuntimeException::class);

        // Above /proc/sys/kernel/pid_max, so nothing is there to read. Not
        // "0.0 seconds": a cost that could not be measured is not a cost of
        // nothing.
        $this->reader->read(4_000_000);
    }

    public function testAPidOfZeroIsAnError(): void
    {
        $this->expectException(RuntimeException::class);

        $this->reader->read(0);
    }

    public function testTheArrayFormNamesBothMemoryNumbers(): void
    {
        $cost = $this->reader->read(getmypid())->toArray();

        self::assertSame(getmypid(), $cost['pid']);
        self::assertArrayHasKey('cpu_seconds_total', $cost);
        self::assertArrayHasKey('rss_bytes', $cost);
        self::assertArrayHasKey('peak_rss_bytes', $cost);
    }
}
