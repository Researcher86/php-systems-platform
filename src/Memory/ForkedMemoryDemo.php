<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Memory;

use RuntimeException;
use Throwable;

/**
 * PLAN Step 15: fork a child over an array the parent already allocated, and
 * measure copy-on-write instead of asserting it. The fork/IPC idiom is the
 * one Workers\ForkedOrderLoader uses: a socket pair opened before the fork,
 * each side closing the end it does not own, pcntl_waitpid() reaping the
 * child.
 *
 * The story is in three snapshots. Right after fork() the child's pages are
 * still the parent's: nothing has been copied, so its RSS lands close to the
 * parent's. Only once the child WRITES to the array does its private memory
 * (RssAnon) grow - copy-on-write made visible (runChild() notes exactly
 * which copy that is).
 */
final readonly class ForkedMemoryDemo
{
    public function __construct(
        private int $elements = 2_000_000,
        private MemoryReporter $reporter = new MemoryReporter(),
    ) {
    }

    public function run(): MemoryDemoResult
    {
        // Allocated in the parent, before the fork, so the child inherits it
        // as shared pages rather than building its own copy.
        $shared = array_fill(0, $this->elements, 0);

        $beforeFork = $this->reporter->snapshot();

        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        if ($pair === false) {
            throw new RuntimeException('Could not create a socket pair for the memory demo.');
        }

        [$parentEnd, $childEnd] = $pair;
        $pid = pcntl_fork();

        if ($pid === -1) {
            fclose($parentEnd);
            fclose($childEnd);

            throw new RuntimeException('Could not fork for the memory demo.');
        }

        if ($pid === 0) {
            fclose($parentEnd);
            $this->runChild($shared, $childEnd);
        }

        fclose($childEnd);
        unset($shared);

        $answer = (string) stream_get_contents($parentEnd);
        fclose($parentEnd);
        pcntl_waitpid($pid, $status);

        if (!pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
            throw new RuntimeException(sprintf('The memory demo child did not complete: %s', $answer));
        }

        $decoded = json_decode($answer, true);

        if (!is_array($decoded) || !isset($decoded['after_fork'], $decoded['after_modification'])) {
            throw new RuntimeException('The memory demo child answered nothing usable.');
        }

        return new MemoryDemoResult(
            beforeFork: $beforeFork,
            afterFork: $this->hydrate($decoded['after_fork']),
            afterModification: $this->hydrate($decoded['after_modification']),
        );
    }

    /**
     * The child half of the fork: snapshot before writing anything, write
     * to every other element of the inherited array, snapshot again, report
     * both, and exit without ever returning into the caller's code.
     *
     * $shared arrives by value while run() still holds it, so the first
     * write makes PHP separate (duplicate) the whole array: the growth
     * measured is that fresh private copy, not only the kernel's
     * page-by-page COW of the inherited one.
     *
     * @param list<int> $shared
     * @param resource  $socket
     */
    private function runChild(array $shared, mixed $socket): never
    {
        foreach ([SIGINT, SIGTERM, SIGCHLD] as $signal) {
            pcntl_signal($signal, SIG_DFL);
        }

        try {
            $afterFork = $this->reporter->snapshot();

            for ($i = 0, $count = count($shared); $i < $count; $i += 2) {
                $shared[$i]++;
            }

            $afterModification = $this->reporter->snapshot();

            fwrite($socket, (string) json_encode([
                'after_fork' => $afterFork,
                'after_modification' => $afterModification,
            ]));
            $exitCode = 0;
        } catch (Throwable $e) {
            fwrite($socket, $e->getMessage());
            $exitCode = 1;
        }

        fclose($socket);

        exit($exitCode);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function hydrate(array $data): MemorySnapshot
    {
        return new MemorySnapshot(
            phpUsage: (int) $data['phpUsage'],
            phpRealUsage: (int) $data['phpRealUsage'],
            rss: isset($data['rss']) ? (int) $data['rss'] : null,
            privateMemory: isset($data['privateMemory']) ? (int) $data['privateMemory'] : null,
            sharedMemory: isset($data['sharedMemory']) ? (int) $data['sharedMemory'] : null,
        );
    }
}
