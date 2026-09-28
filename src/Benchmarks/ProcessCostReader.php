<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Benchmarks;

use PhpSystemsPlatform\Memory\ProcStatusReader;
use RuntimeException;

/**
 * Reads one process's cost out of /proc - the CPU and memory behind PLAN
 * Step 29's Test A.
 *
 * Two files, because the kernel keeps the two numbers in two shapes.
 * /proc/<pid>/status is a flat key/value list and the platform already has a
 * reader for it (Memory\ProcStatusReader), which is where VmRSS and the
 * kernel's own peak, VmHWM, come from. /proc/<pid>/stat is a single line with
 * the process name wedged into the second field, so it is parsed here - after
 * the last closing parenthesis, which is the only reliable way past a name
 * that may itself contain spaces or parentheses.
 */
final class ProcessCostReader
{
    public function __construct(
        private readonly ProcStatusReader $status = new ProcStatusReader(),
    ) {
    }

    /**
     * @throws RuntimeException the process is gone, or this is not a Linux
     *                          host - both of which a load test should say
     *                          out loud rather than report a zero cost
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

        // Fields 1 and 2 are the pid and the command in parentheses; the
        // fields after them are state, ppid, ... and utime is the 14th field of
        // the line, which is the 12th token after that parenthesis.
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
