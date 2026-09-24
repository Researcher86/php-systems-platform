<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Memory;

use RuntimeException;

/**
 * Parses /proc/<pid>/status - a flat "Key:\tvalue" list that mixes
 * kB-backed memory fields (VmRSS, RssAnon, RssShmem, VmSize, ...) with plain
 * integers (Threads, Pid) and arbitrary strings (Name). A value ending in
 * " kB" is converted to bytes on the way in, so every caller works in one
 * unit instead of remembering which fields are pages, which are kB.
 *
 * The mechanism mirrors php-memory-lab's own ProcStatusReader - that project
 * cannot be a runtime dependency (PLAN Step 15 says so explicitly), so the
 * platform re-implements the one reader its fork demo needs rather than the
 * project's whole measurement stack.
 */
final readonly class ProcStatusReader
{
    /**
     * @param int $pid process to inspect; 0 means the current process
     *
     * @return array<string, int|string>
     *
     * @throws RuntimeException the file cannot be read - a non-Linux host,
     *                          or a pid that has already gone away
     */
    public function read(int $pid = 0): array
    {
        $target = $pid > 0 ? $pid : getmypid();

        if ($target === false) {
            throw new RuntimeException('Could not determine the current process id.');
        }

        $path = sprintf('/proc/%d/status', $target);
        $content = @file_get_contents($path);

        if ($content === false) {
            throw new RuntimeException(sprintf('Could not read "%s".', $path));
        }

        return $this->parse($content);
    }

    /**
     * @return array<string, int|string>
     */
    public function parse(string $content): array
    {
        $fields = [];

        foreach (explode("\n", $content) as $line) {
            $colon = strpos($line, ':');

            if ($colon === false) {
                continue;
            }

            $fields[substr($line, 0, $colon)] = $this->convert(trim(substr($line, $colon + 1)));
        }

        return $fields;
    }

    private function convert(string $value): int|string
    {
        if (preg_match('/^(\d+)\s+kB$/', $value, $matches) === 1) {
            return (int) $matches[1] * 1024;
        }

        if (preg_match('/^\d+$/', $value) === 1) {
            return (int) $value;
        }

        return $value;
    }
}
