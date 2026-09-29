<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Benchmarks;

use PhpSystemsPlatform\Memory\ProcStatusReader;
use RuntimeException;

/**
 * Reads a ProcessCost out of /proc: VmRSS and VmHWM from /proc/<pid>/status
 * (via Memory\ProcStatusReader), utime and stime from /proc/<pid>/stat.
 */
final class ProcessCostReader
{
    public function __construct(
        private readonly ProcStatusReader $status = new ProcStatusReader(),
    ) {
    }

    /**
     * @throws RuntimeException the process is gone or this is not Linux -
     *                          better than silently reporting a zero cost
     */
    public function read(int $pid): ProcessCost
    {
        if ($pid <= 0) {
            throw new RuntimeException('A process cost needs a real pid.');
        }

        return new ProcessCost(
            $pid,
            $this->cpuSeconds($pid),
            $this->rss($pid, 'VmRSS'),
            $this->rss($pid, 'VmHWM'),
        );
    }

    private function cpuSeconds(int $pid): float
    {
        $line = @file_get_contents(sprintf('/proc/%d/stat', $pid));

        if ($line === false) {
            throw new RuntimeException(sprintf('Could not read "/proc/%d/stat".', $pid));
        }

        // Field 2 is the command in parentheses and may itself contain spaces
        // or parentheses, so parsing starts after the LAST ')'. utime and
        // stime are fields 14 and 15, i.e. tokens 11 and 12 after it.
        $closing = strrpos($line, ')');

        if ($closing === false) {
            throw new RuntimeException(sprintf('"/proc/%d/stat" is not in the shape this reader expects.', $pid));
        }

        $fields = preg_split('/\s+/', trim(substr($line, $closing + 1))) ?: [];
        $utime = (int) ($fields[11] ?? 0);
        $stime = (int) ($fields[12] ?? 0);

        return ($utime + $stime) / ProcessCost::CLOCK_TICKS_PER_SECOND;
    }

    private function rss(int $pid, string $field): int
    {
        $status = $this->status->read($pid);

        return (int) ($status[$field] ?? 0);
    }
}
