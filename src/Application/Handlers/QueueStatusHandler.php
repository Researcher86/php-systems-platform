<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Application\Handlers;

use PhpSystemsPlatform\Http\Request;
use PhpSystemsPlatform\Http\Response;
use PhpSystemsPlatform\Queue\QueueJournal;

/**
 * The platform's HTTP view of the queue.
 *
 * GET /queue/status answers the same journal-derived counters that the
 * `queue:status` CLI prints, so the queue depth and job traffic are
 * observable from the outside without owning a queue process. Read-only by
 * construction - it only replays the journal - so it is safe to expose
 * wherever the HTTP server already is.
 */
final readonly class QueueStatusHandler
{
    public function __construct(
        private string $logPath,
    ) {
    }

    /**
     * @param array<string, string> $params route parameters (none for /queue/status)
     */
    public function __invoke(Request $request, array $params): Response
    {
        return Response::json([
            'queue' => new QueueJournal($this->logPath)->snapshot(),
        ]);
    }
}
