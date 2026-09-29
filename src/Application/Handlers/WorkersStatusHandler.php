<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Application\Handlers;

use PhpSystemsPlatform\Http\Request;
use PhpSystemsPlatform\Http\Response;

/**
 * GET /workers - the queue consumer's worker snapshot, the same one
 * `workers:status` prints. The consumer writes the file on its own
 * schedule; this endpoint only reads it.
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
        // The file can vanish between the check and the read; a failed read
        // then means "not running" rather than a PHP warning.
        $json = is_file($this->statusPath) ? @file_get_contents($this->statusPath) : false;

        if ($json === false) {
            return Response::json(['running' => false, 'workers' => []]);
        }

        $decoded = json_decode($json, true);

        return Response::json([
            'running' => true,
            'workers' => is_array($decoded) ? $decoded : [],
        ]);
    }
}
