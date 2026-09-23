<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Application\Handlers;

use PhpSystemsPlatform\Http\Request;
use PhpSystemsPlatform\Http\Response;

/**
 * The platform's HTTP view of the queue consumer's worker lifecycle.
 *
 * GET /workers answers the same snapshot `workers:status` prints: each
 * forwarder's id, pid, state, current job and the completed/failed counters
 * the consumer attributes from the journal. The consumer writes the file on
 * its own schedule, so this endpoint is read-only by construction and never
 * has to own a worker process to answer.
 */
final readonly class WorkersStatusHandler
{
    public function __construct(
        private string $statusPath,
    ) {
    }

    /**
     * @param array<string, string> $params route parameters (none for /workers)
     */
    public function __invoke(Request $request, array $params): Response
    {
        if (!is_file($this->statusPath)) {
            return Response::json(['running' => false, 'workers' => []]);
        }

        $decoded = json_decode((string) file_get_contents($this->statusPath), true);

        return Response::json([
            'running' => true,
            'workers' => is_array($decoded) ? $decoded : [],
        ]);
    }
}
