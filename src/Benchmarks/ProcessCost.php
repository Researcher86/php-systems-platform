<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Benchmarks;

/**
 * What one process cost while it was under load - the CPU and memory half of
 * PLAN Step 29's Test A, read from the kernel rather than guessed at.
 *
 * CPU is the utime+stime pair out of /proc/<pid>/stat, which is where the
 * kernel accounts every tick a process was on a CPU. Memory is the peak
 * resident set the kernel already tracks (VmHWM), not a sample a benchmark
 * takes on a timer: a sampled peak can only ever be as high as the sampler
 * happened to look, and "the high-water mark the kernel recorded" is the
 * honest version of the same question.
 *
 * The numbers are a delta by design. A process that has been alive for a
 * while has already spent CPU on being started, so what a load phase costs is
 * the difference between two readings taken around it, and the runner takes
 * them itself.
 */
final readonly class ProcessCost
{
    /**
     * Linux reports process CPU in clock ticks, and CLK_TCK is 100 on every
     * platform this project runs on (x86_64 and arm64 Linux, CI and the
     * container alike). The consequence is stated rather than hidden: CPU
     * seconds here have 10ms resolution, which is finer than a load phase
     * takes to run and far finer than a phase's own numbers are quoted to.
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
