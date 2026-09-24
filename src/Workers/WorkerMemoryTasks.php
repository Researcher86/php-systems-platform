<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Workers;

use PhpSystemsPlatform\Memory\MemoryReporter;
use PhpSystemsPlatform\Memory\MemorySnapshot;
use PhpWorkerPool\Protocol\Request;
use PhpWorkerPool\Protocol\Response;

/**
 * The pool-side half of PLAN Step 16: a task that lets a worker measure and
 * grow its own memory, the only way to answer "how much does this worker
 * actually cost" - php-worker-pool keeps its own telemetry internal to the
 * Master for its recycling policy, with no wire action that reads it back.
 *
 * `memory.hold` reports the worker BEFORE it allocates anything - which, on
 * a worker's first call, is its state right out of the fork, still mostly
 * the Master's own pages - then allocates and RETAINS an array of the given
 * size (appended to `$held`, an instance property, which survives for the
 * life of this worker process because the pool keeps this same handler
 * instance alive across every request it answers), then reports again.
 * `pid` on the answer is what lets the CLI tell one worker's numbers from
 * another's without needing anything from the pool itself.
 */
final class WorkerMemoryTasks
{
    private const int MAX_ELEMENTS = 5_000_000;

    /** @var list<list<int>> everything this worker has been asked to hold, kept for its whole life */
    private array $held = [];

    /**
     * @return \Closure(Request): Response
     */
    public function handler(): \Closure
    {
        return fn (Request $request): Response => match ($request->action) {
            'memory.hold' => $this->hold($request->params),
            default => Response::error('unknown_action'),
        };
    }

    /**
     * @param array<string, mixed> $params
     */
    private function hold(array $params): Response
    {
        $elements = $params['elements'] ?? 0;

        if (!is_int($elements) || $elements < 1 || $elements > self::MAX_ELEMENTS) {
            return Response::error('bad_params', [
                'reason' => sprintf('elements must be an int between 1 and %d.', self::MAX_ELEMENTS),
            ]);
        }

        $reporter = new MemoryReporter();
        $before = $reporter->snapshot();

        $this->held[] = array_fill(0, $elements, 0);

        $after = $reporter->snapshot();

        return Response::of([
            'pid' => getmypid(),
            // How many allocations this worker has accumulated over its
            // whole life, not just this call - the number that makes
            // $held's role (retaining what a real long-lived worker holds,
            // not a scratch variable a single call could do without)
            // externally checkable rather than merely asserted by comment.
            'holds' => count($this->held),
            'before' => self::encode($before),
            'after' => self::encode($after),
        ]);
    }

    /**
     * @return array{phpUsage: int, phpRealUsage: int, rss: ?int, privateMemory: ?int, sharedMemory: ?int}
     */
    private static function encode(MemorySnapshot $snapshot): array
    {
        return [
            'phpUsage' => $snapshot->phpUsage,
            'phpRealUsage' => $snapshot->phpRealUsage,
            'rss' => $snapshot->rss,
            'privateMemory' => $snapshot->privateMemory,
            'sharedMemory' => $snapshot->sharedMemory,
        ];
    }
}
