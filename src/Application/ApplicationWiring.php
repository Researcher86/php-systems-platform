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
 * This exists because the alternative was ten positional parameters on the
 * composition root's application() call, two of which were adjacent strings
 * (the queue journal path and the worker status path). Swapping them compiles,
 * passes a level-6 PHPStan run and a clean format check, and silently points
 * GET /queue/status at the worker snapshot file. Named arguments fixed the
 * call site; this fixes the signature, so the mistake is unrepresentable
 * instead of merely discouraged.
 *
 * The nullables keep their meaning from the original parameters: a null
 * producer is a synchronous write path with no queue, a null runner is a
 * platform with no pool behind GET /parallel, and a null metrics reporter or
 * trace is a serve that was not asked to observe itself. A caller holding
 * fewer pieces gets the earlier behavior back rather than a crash.
 */
final readonly class ApplicationWiring
{
    public function __construct(
        public Database $database,
        public CacheService $cache,
        public ?Producer $producer = null,
        public ?ConcurrentTaskRunner $runner = null,
        public string $queueLogPath = '',
        public string $workersStatusPath = '',
        public ?int $maxQueueSize = null,
        public ?WorkerFailureInjector $failureInjector = null,
        public ?MetricsReporter $metricsReporter = null,
        public ?Trace $trace = null,
        public ?Logger $logger = null,
    ) {
    }

    /**
     * The one journal this Application reads the queue through, built once.
     *
     * Both the backpressure policy on POST /orders and the counters on
     * GET /queue/status need the same replay, and QueueJournal caches it
     * against the file's size and mtime - so the two share one instance and
     * a request that both enqueues and then reports costs one replay, not
     * two. A caller with no journal path gets null and the backpressure
     * policy stays off, exactly as an empty $queueLogPath used to mean.
     */
    public function queueJournal(): ?QueueJournal
    {
        return $this->queueLogPath === '' ? null : new QueueJournal($this->queueLogPath);
    }
}
