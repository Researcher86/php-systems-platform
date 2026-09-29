<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Workers;

use PhpWorkerPool\IPC\ConnectionClosedException;
use PhpWorkerPool\Protocol\Request as WorkerRequest;
use PhpWorkerPool\Sdk\ConnectionFailedException;
use PhpWorkerPool\Sdk\RequestTimedOutException;
use PhpWorkerPool\Sdk\ServerErrorException;
use PhpWorkerPool\Sdk\WorkerPoolClient;

/**
 * PLAN Step 22's client side of the worker-crash scenario, shared by
 * `failure:demo`, POST /debug/fail-worker and the integration tests.
 *
 * One call drives the whole PLAN sequence and records each phase's evidence
 * straight off the pool's own bookkeeping:
 *
 *     worker crashes          -> the worker.crash task SIGKILLs a worker
 *     worker manager detects  -> the client hears worker_crashed (the
 *                                Master failed the request the dead worker
 *                                held - reapCrashedWorkers)
 *     worker removed          -> WorkerPoolClient::stats() no longer lists
 *                                the dead pid
 *     replacement started     -> stats() lists a pid that was not there
 *                                before (reapDeadWorkers forked it)
 *
 * stats() is answered by the Master itself, never by a worker, so taking a
 * read after the crash costs no pool capacity and observes the replacement
 * even while the pool is one short.
 */
final readonly class WorkerFailureInjector
{
    private const float REPLACEMENT_DEADLINE_SECONDS = 5.0;

    private const float POLL_INTERVAL_SECONDS = 0.05;

    public function __construct(
        private WorkerPoolClient $client,
    ) {
    }

    /**
     * Crash one worker and wait for the pool to notice and replace it.
     *
     * @return array{error: string, crash_detected: bool, detected_ms: ?float, crashed_pid: ?int, worker_removed: bool, removed_ms: ?float, replacement_started: bool, replaced_ms: ?float, replacement_pid: ?int, pool_size: int, before: list<int>, after: list<int>}
     */
    public function crashOneWorker(): array
    {
        $before = $this->pids() ?? [];
        $started = microtime(true);

        $error = 'no_error';

        try {
            $this->client->call(new WorkerRequest('worker.crash'));
        } catch (ServerErrorException $e) {
            // The pool's regular answer: the worker that took the request
            // was reaped before it could answer.
            $error = $e->error;
        } catch (ConnectionClosedException | ConnectionFailedException) {
            // A race: the crash was seen through the connection instead of
            // through the worker_crashed message. The observable fact is the
            // same one the demo reports - the worker died on request.
            $error = 'connection_dropped';
        } catch (RequestTimedOutException) {
            // The master did not answer within the request budget. Whatever
            // the reason, the poll loop below still gets the ground truth
            // off stats(): the dead pid either leaves the list or it does
            // not, and the report reflects what the pool's bookkeeping says.
            $error = 'request_timed_out';
        }

        $detectedMs = (microtime(true) - $started) * 1000;

        $removedMs = null;
        $replacedMs = null;
        $replacementPid = null;
        $after = $before;

        $deadline = microtime(true) + self::REPLACEMENT_DEADLINE_SECONDS;

        while ($removedMs === null || $replacedMs === null) {
            // A failed stats() read says nothing about the pool, so it must
            // not be mistaken for an empty one - that would record "every
            // worker removed" and blame an arbitrary pid for the crash.
            $current = $this->pids();
            $elapsedMs = (microtime(true) - $started) * 1000;

            if ($current !== null) {
                $after = $current;

                if ($removedMs === null && array_diff($before, $after) !== []) {
                    $removedMs = $elapsedMs;
                }

                $newPids = array_values(array_diff($after, $before));

                if ($replacedMs === null && $newPids !== []) {
                    $replacedMs = $elapsedMs;
                    $replacementPid = $newPids[0];
                }
            }

            if (microtime(true) >= $deadline) {
                break;
            }

            usleep((int) (self::POLL_INTERVAL_SECONDS * 1_000_000));
        }

        // The pid that was listed before the crash and is gone from the
        // final snapshot is the one that died; everything else is the pool
        // holding steady.
        $crashedPid = array_values(array_diff($before, $after))[0] ?? null;

        return [
            'error' => $error,
            // The pool's own bookkeeping then decides how fast it noticed:
            // crash_detected is the client-side signal that the worker that
            // took the crash request never answered it (the SIGKILL, or the
            // connection dying with it); worker_removed and
            // replacement_started are the pool's side, read off stats().
            'crash_detected' => in_array($error, ['worker_crashed', 'connection_dropped', 'request_timed_out'], true),
            'detected_ms' => round($detectedMs, 1),
            'crashed_pid' => $crashedPid,
            'worker_removed' => $removedMs !== null,
            'removed_ms' => $removedMs === null ? null : round($removedMs, 1),
            'replacement_started' => $replacedMs !== null,
            'replaced_ms' => $replacedMs === null ? null : round($replacedMs, 1),
            'replacement_pid' => $replacementPid,
            'pool_size' => count($after),
            'before' => $before,
            'after' => $after,
        ];
    }

    /**
     * The pool's current worker pids, or null when the Master could not be
     * asked right now - a pool a moment into replacing a worker may drop a
     * connection or be too busy reaping to answer in time.
     *
     * @return list<int>|null
     */
    private function pids(): ?array
    {
        try {
            return array_map(
                static fn (array $row): int => (int) $row['pid'],
                $this->client->stats(),
            );
        } catch (ConnectionClosedException | ConnectionFailedException | ServerErrorException | RequestTimedOutException) {
            return null;
        }
    }
}
