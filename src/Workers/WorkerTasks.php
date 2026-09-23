<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Workers;

use PhpWorkerPool\Protocol\Request;
use PhpWorkerPool\Protocol\Response;

/**
 * The worker pool's per-worker tasks - the only code the Master's forked
 * workers run (see bin/worker.php). Like the DB and cache components, the pool
 * keeps the mechanics and the platform keeps the behavior: the Master owns
 * process, dispatch and lifecycle, and this file decides what a task IS.
 *
 * hash_chunk is the example CPU-bound task the demo splits across workers:
 * a chain of sha256 hashes. Each worker folds its own chain and reports how
 * many iterations it ran and how long it took, so the controller can show
 * that N chunks actually ran on N workers side by side rather than serially.
 */
final class WorkerTasks
{
    /**
     * @return \Closure(Request): Response
     */
    public static function handler(): \Closure
    {
        return static fn (Request $request): Response => match ($request->action) {
            'ping' => Response::of(['pong' => true]),
            'hash_chunk' => self::hashChunk($request->params),
            default => Response::error('unknown_action'),
        };
    }

    /**
     * Compute a hash chain of $iterations folds over sha256, starting from
     * the seed, and report the work actually done. A deliberate error, not a
     * thrown exception, for a payload that cannot describe a task - the
     * controller then sees a degraded chunk instead of a crashed worker.
     *
     * @param array<string, mixed> $params
     */
    public static function hashChunk(array $params): Response
    {
        $iterations = $params['iterations'] ?? 0;

        if (!is_int($iterations) || $iterations < 1 || $iterations > 1_000_000) {
            return Response::error('bad_params', ['reason' => 'iterations must be an int between 1 and 1_000_000.']);
        }

        $seed = is_string($params['seed'] ?? null) ? $params['seed'] : '';

        $started = microtime(true);
        $checksum = $seed === '' ? '0' : hash('sha256', $seed);

        for ($i = 0; $i < $iterations; $i++) {
            $checksum = hash('sha256', $checksum);
        }

        return Response::of([
            'iterations' => $iterations,
            'seed' => $seed,
            'microseconds' => (int) round((microtime(true) - $started) * 1_000_000),
            'checksum' => $checksum,
        ]);
    }
}
