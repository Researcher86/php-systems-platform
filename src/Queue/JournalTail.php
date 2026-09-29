<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Queue;

/**
 * An incremental reader of the append-only job journal: each read() returns
 * only the rows appended since the previous one, last write per job winning
 * (FileStorage::load()'s replay, over the new bytes only).
 *
 * The journal is never compacted, so a long-running reader that replayed the
 * whole file on every pass would spend CPU proportional to the queue's entire
 * history. Append-only means a byte offset is enough to know what is new.
 */
final class JournalTail
{
    private int $offset = 0;

    public function __construct(
        private readonly string $logPath,
    ) {
    }

    /**
     * @return array<string, array<string, mixed>> job id => its latest row among the new ones
     */
    public function read(): array
    {
        // Other processes append to this file, and PHP's stat cache is only
        // invalidated by this process's own writes.
        clearstatcache(true, $this->logPath);
        $size = @filesize($this->logPath);

        if ($size === false || $size <= $this->offset) {
            return [];
        }

        $chunk = (string) @file_get_contents($this->logPath, false, null, $this->offset);
        $end = strrpos($chunk, "\n");

        // Only complete lines are consumed: a line still being written is
        // picked up whole on the next call.
        if ($end === false) {
            return [];
        }

        $this->offset += $end + 1;
        $rows = [];

        foreach (explode("\n", substr($chunk, 0, $end)) as $line) {
            $decoded = json_decode($line, true);

            // A line that is not a record is skipped rather than fatal: a
            // tailing reader must keep going, and the full replay
            // (FileStorage::load()) is where corruption is reported.
            if (is_array($decoded) && isset($decoded['key'], $decoded['data']) && is_array($decoded['data'])) {
                /** @var array<string, mixed> $data */
                $data = $decoded['data'];
                $rows[(string) $decoded['key']] = $data;
            }
        }

        return $rows;
    }
}
