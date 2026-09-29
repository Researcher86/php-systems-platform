<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Application;

use PhpJobQueue\Producer\Producer;
use PhpMiniHttpServer\Support\Logger;
use PhpSystemsPlatform\Cache\CacheService;
use PhpSystemsPlatform\Observability\MetricsReporter;
use PhpSystemsPlatform\Observability\Trace;
use PhpSystemsPlatform\Queue\QueueJournal;
use PhpSystemsPlatform\Storage\Database;
use PhpSystemsPlatform\Workers\ConcurrentTaskRunner;
use PhpSystemsPlatform\Workers\WorkerFailureInjector;

/**
 * Everything the platform's Application is built out of, in one named object.
 *
 * Replaces a long positional parameter list that had two adjacent strings
 * (queue journal path, worker status path): swapping them type-checked and
 * silently pointed GET /queue/status at the worker snapshot file.
 *
 * Every nullable means "that part is not wired": no producer is a
 * synchronous write path, no runner is a GET /parallel that answers 503, no
 * metrics reporter or trace is a serve that does not observe itself.
 */
final readonly class ApplicationWiring
{
    public function __construct(
        public Database $database,
        public CacheService $cache,
        public ?Producer $producer = null,
        public ?ConcurrentTaskRunner $runner = null,
        // One shared instance for backpressure, GET /queue/status and the
        // metrics reporter: QueueJournal caches its replay against the
        // file's size and mtime, so sharing it means one replay per change,
        // not one per reader. Null: no backpressure and no status route.
        public ?QueueJournal $queueJournal = null,
        public string $workersStatusPath = '',
        public ?int $maxQueueSize = null,
        public ?WorkerFailureInjector $failureInjector = null,
        public ?MetricsReporter $metricsReporter = null,
        public ?Trace $trace = null,
        public ?Logger $logger = null,
    ) {
    }
}
