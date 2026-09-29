<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Application\Handlers;

use PhpSystemsPlatform\Http\Request;
use PhpSystemsPlatform\Http\Response;
use PhpSystemsPlatform\Queue\QueueJournal;

/**
 * GET /queue/status - the journal-derived counters `queue:status` prints.
 * Read-only: it only replays the journal.
 *
 * The journal is injected, not built per request: it caches its replay
 * against the file's size and mtime, and a fresh instance per request would
 * replay the whole journal every time.
 */
final readonly class QueueStatusHandler
{
    public function __construct(
        private QueueJournal $journal,
    ) {
    }

    /**
     * @param array<string, string> $params route parameters (none for /queue/status)
     */
    public function __invoke(Request $request, array $params): Response
    {
        return Response::json([
            'queue' => $this->journal->snapshot(),
        ]);
    }
}
