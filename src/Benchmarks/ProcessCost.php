<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Benchmarks;

/**
 * One reading of a process's CPU and memory, from the kernel.
 *
 * CPU is utime+stime from /proc/<pid>/stat; a phase's cost is the delta
 * between two readings (cpuSince()), since the process spent CPU before the
 * phase began. Peak memory is the kernel's own high-water mark (VmHWM) -
 * over the process's lifetime, and exact, unlike a timer-sampled peak.
 */
final readonly class ProcessCost
{
    /**
     * CLK_TCK on every Linux this project runs on (x86_64 and arm64), so CPU
     * seconds have 10 ms resolution - ample for phases that take seconds.
     */
    public const int CLOCK_TICKS_PER_SECOND = 100;

    public function __construct(
        public int $pid,
        public float $cpuSeconds,
        public int $rssBytes,
        public int $peakRssBytes,
    ) {
    }

    /**
     * The CPU this process spent between two readings, in seconds.
     */
    public function cpuSince(self $before): float
    {
        return round(max(0.0, $this->cpuSeconds - $before->cpuSeconds), 3);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'pid' => $this->pid,
            'cpu_seconds_total' => round($this->cpuSeconds, 3),
            'rss_bytes' => $this->rssBytes,
            'peak_rss_bytes' => $this->peakRssBytes,
        ];
    }
}
