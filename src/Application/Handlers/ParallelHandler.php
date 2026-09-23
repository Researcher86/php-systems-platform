<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Application\Handlers;

use PhpSystemsPlatform\Http\Request;
use PhpSystemsPlatform\Http\Response;
use PhpSystemsPlatform\Workers\ConcurrentTaskRunner;
use PhpWorkerPool\Protocol\Request as WorkerRequest;

/**
 * The example the worker phase is about: a CPU-bound task split into parts
 * and run side by side on several worker processes.
 *
 * GET /parallel?work=200000&split=4  - fold 200k hash iterations, split into
 * four chunks, one per worker. The handler halves the work, fans the chunks
 * out to the pool in parallel (each chunk is a separate request, already in
 * flight before any of them is awaited), then aggregates the results by
 * their slot. A chunk that times out or is rejected degrades into a missing
 * slot - the pool's allWithin() bargain - and is reported as such instead of
 * failing the whole request.
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

        if ($this->runner === null) {
            return Response::json(['error' => 'worker pool is not configured.'], 503);
        }

        $base = intdiv($work, $split);
        $requests = [];

        for ($index = 0; $index < $split; $index++) {
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

    private function intOrDefault(mixed $value, int $default): int
    {
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        if (is_int($value)) {
            return $value;
        }

        return $default;
    }
}
