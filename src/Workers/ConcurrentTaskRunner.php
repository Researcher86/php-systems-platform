<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Workers;

use PhpWorkerPool\Protocol\Request;
use PhpWorkerPool\Sdk\WorkerPoolClient;

/**
 * The platform's fan-out over one worker pool connection: send every chunk
 * into the pool at once, then collect. The pool answers each chunk on a
 * separate worker process, so N chunks genuinely run side by side.
 *
 * Two collection shapes, mirroring the client's two bargains: run() is
 * all-or-fail - one bad chunk fails the call, because the caller asked for
 * all of them; runWithin() degrades - it spends one shared budget on the
 * whole group and returns only the chunks that made it, keyed by their
 * position, which is the number an HTTP handler actually has.
 */
final readonly class ConcurrentTaskRunner
{
    public function __construct(
        private WorkerPoolClient $client,
    ) {
    }

    /**
     * @param Request ...$requests each a chunk of the task
     *
     * @return list<array<string, mixed>> payloads in the order requested
     */
    public function run(Request ...$requests): array
    {
        $pending = array_map([$this->client, 'send'], $requests);

        return $this->client->all(...$pending);
    }

    /**
     * @param Request ...$requests each a chunk of the task
     *
     * @return array<int, array<string, mixed>> payloads keyed by request
     *         position; a position that timed out or was rejected is absent
     */
    public function runWithin(float $seconds, Request ...$requests): array
    {
        $pending = array_map([$this->client, 'send'], $requests);

        return $this->client->allWithin($seconds, ...$pending);
    }
}
