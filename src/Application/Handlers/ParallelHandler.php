<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Application\Handlers;

use PhpSystemsPlatform\Http\Request;
use PhpSystemsPlatform\Http\Response;
use PhpSystemsPlatform\Workers\ConcurrentTaskRunner;
use PhpWorkerPool\Protocol\Request as WorkerRequest;

/**
 * GET /parallel?work=200000&split=4 - a CPU-bound task (hash iterations)
 * split into chunks that run side by side on the worker pool.
 *
 * All chunks are in flight before any is awaited; results are aggregated by
 * slot. A chunk that times out or is rejected becomes a missing slot and is
 * reported under "degraded" (206) instead of failing the whole request; only
 * when no chunk completes is the answer a 503.
 */
final readonly class ParallelHandler
{
    public function __construct(
        private ?ConcurrentTaskRunner $runner = null,
    ) {
    }

    /**
     * @param array<string, string> $params route parameters (none for /parallel)
     */
    public function __invoke(Request $request, array $params): Response
    {
        $work = $this->intOrDefault($request->query['work'] ?? null, 100_000);
        $split = $this->intOrDefault($request->query['split'] ?? null, 4);

        if ($work < 1 || $work > 1_000_000) {
            return Response::json(['error' => 'work must be between 1 and 1_000_000.'], 400);
        }

        if ($split < 1 || $split > 16) {
            return Response::json(['error' => 'split must be between 1 and 16.'], 400);
        }

        // A chunk needs at least one iteration: the worker refuses an empty
        // one, which would read as a degraded answer no worker caused.
        if ($split > $work) {
            return Response::json(['error' => 'split must not exceed work.'], 400);
        }

        if ($this->runner === null) {
            return Response::json(['error' => 'worker pool is not configured.'], 503);
        }

        $base = intdiv($work, $split);
        $requests = [];

        for ($index = 0; $index < $split; $index++) {
            // The last chunk takes the remainder of the integer division.
            $iterations = $index === $split - 1 ? $work - $base * ($split - 1) : $base;

            $requests[] = new WorkerRequest('hash_chunk', [
                'seed' => 'parallel-' . $index,
                'iterations' => $iterations,
            ]);
        }

        $started = microtime(true);
        $answers = $this->runner->runWithin(5.0, ...$requests);
        $wallMicroseconds = (int) round((microtime(true) - $started) * 1_000_000);

        $chunks = [];
        $degraded = [];

        for ($index = 0; $index < $split; $index++) {
            if (isset($answers[$index])) {
                $chunks[$index] = [
                    'index' => $index,
                    'iterations' => $answers[$index]['iterations'] ?? 0,
                    'microseconds' => $answers[$index]['microseconds'] ?? 0,
                    'checksum' => $answers[$index]['checksum'] ?? '',
                ];
            } else {
                $degraded[] = $index;
            }
        }

        $completed = count($chunks);

        if ($completed === 0) {
            return Response::json(['error' => 'No worker chunk could complete.'], 503);
        }

        return Response::json([
            'totalIterations' => $work,
            'requestedChunks' => $split,
            'completedChunks' => $completed,
            'wallMicroseconds' => $wallMicroseconds,
            'chunks' => array_values($chunks),
            'degraded' => $degraded,
            'note' => $degraded === [] ? null : 'some chunks degraded; see degraded indices.',
        ], $degraded === [] ? 200 : 206);
    }

    /**
     * A non-negative decimal query value, or the default for anything else
     * (absent, signed, non-numeric, an array). Out-of-range values are left
     * for the caller to reject.
     */
    private function intOrDefault(mixed $value, int $default): int
    {
        return is_string($value) && ctype_digit($value) ? (int) $value : $default;
    }
}
