<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Demo;

use PhpSystemsPlatform\Queue\Jobs\FailingJob;
use PhpSystemsPlatform\Queue\JournalOnlyQueue;
use PhpSystemsPlatform\Queue\QueueJournal;
use PhpSystemsPlatform\Support\HttpProbe;
use PhpSystemsPlatform\Support\OwnedProcess;
use PhpWorkerPool\Sdk\WorkerPoolClient;
use RuntimeException;

/**
 * The whole platform told as one story, run against the real binaries and
 * the real ports (PLAN Step 26).
 *
 * Every line is backed by a live fact: a serve process and a queue:consume
 * process, 100 orders over real HTTP, the journal proving 100 published jobs,
 * a pool worker crashed through POST /debug/fail-worker and replaced by the
 * pool Master, a demo.failing job retried by the running consumer until it
 * spends its attempt budget, a final /metrics snapshot, and a graceful
 * shutdown verified from each child's own "stopped" line.
 */
final class PlatformDemo
{
    private const int ORDER_COUNT = 100;

    private const float PORT_PROBE_SECONDS = 0.3;

    private const float COMPONENT_START_DEADLINE_SECONDS = 5.0;

    private const float SERVE_START_DEADLINE_SECONDS = 25.0;

    private const float WORKER_START_DEADLINE_SECONDS = 30.0;

    private const float WORKER_DRAIN_DEADLINE_SECONDS = 120.0;

    private const float RETRY_POLL_DEADLINE_SECONDS = 30.0;

    private const float CHILD_STOP_DEADLINE_SECONDS = 20.0;

    /** How long the failure-path cleanup gives each child before SIGKILL. */
    private const float CLEANUP_STOP_DEADLINE_SECONDS = 3.0;

    /** @var array<string, mixed> */
    private readonly array $config;

    private readonly string $host;

    private readonly int $httpPort;

    private readonly int $dbPort;

    private readonly int $cachePort;

    private readonly string $queueLog;

    private readonly string $workersStatusFile;

    private readonly string $poolSocket;

    private readonly string $dataDir;

    private readonly string $logDir;

    private readonly string $binPath;

    private readonly int $orderedWorkers;

    private readonly HttpProbe $http;

    private int $exitCode = 0;

    private ?OwnedProcess $serve = null;

    private ?OwnedProcess $consumer = null;

    public function __construct()
    {
        $this->config = require dirname(__DIR__, 2) . '/config/platform.php';

        $http = (array) $this->config['http'];
        $database = (array) $this->config['database'];
        $workers = (array) $this->config['workers'];

        $this->host = (string) $http['host'];
        $this->httpPort = (int) $http['port'];
        $this->dbPort = (int) $database['port'];
        $this->cachePort = (int) $this->config['cache']['port'];
        $this->queueLog = (string) $this->config['queue']['data_dir'] . '/queue.log';
        $this->workersStatusFile = (string) $workers['data_dir'] . '/workers.status.json';
        $this->poolSocket = (string) $workers['socket'];
        $this->dataDir = dirname((string) $database['data_dir']);
        $this->binPath = dirname(__DIR__, 2) . '/bin/platform.php';
        $this->logDir = sys_get_temp_dir() . '/php-systems-platform-demo-' . uniqid('', true);
        $this->orderedWorkers = (int) $workers['count'];
        $this->http = new HttpProbe(sprintf('http://%s:%d', $this->host, $this->httpPort));
    }

    public function run(): int
    {
        try {
            $this->abortIfPlatformAlreadyRunning();
            OwnedProcess::stopStaleDatabaseServer($this->host, $this->dbPort, $this->dataDir . '/db', self::CHILD_STOP_DEADLINE_SECONDS);
            OwnedProcess::removeTree($this->dataDir);
            OwnedProcess::mkdir($this->logDir);

            // The pool Master autoscales from a floor of two, so the story
            // could only point at a pool of workers.count after the first
            // burst. Raising the floor (inherited by serve, which launches
            // the pool) makes it that size from start to finish.
            putenv(sprintf('WORKER_POOL_MIN=%d', $this->orderedWorkers));

            $this->startupSections();
            $this->createOrders();
            $this->reportPublishedJobs();
            $this->processJobs();
            $this->injectFailure();
            $this->demonstrateRetry();
            $this->finalStatistics();
            $this->gracefulShutdown();
        } catch (RuntimeException $e) {
            fwrite(STDERR, sprintf('demo: %s%s', $e->getMessage(), PHP_EOL));
            $this->exitCode = 1;
        } finally {
            // Consumer first: it drains through the pool that serve owns.
            // After a graceful shutdown both are already gone and this is a
            // no-op.
            $this->consumer?->stop(self::CLEANUP_STOP_DEADLINE_SECONDS);
            $this->serve?->stop(self::CLEANUP_STOP_DEADLINE_SECONDS);

            // The children's stdout/stderr are the only diagnostics a failed
            // run leaves behind, so they are kept (and pointed at) on failure.
            if ($this->exitCode === 0) {
                OwnedProcess::removeTree($this->logDir);
            } elseif (is_dir($this->logDir)) {
                fwrite(STDERR, sprintf('demo: child process logs kept in %s%s', $this->logDir, PHP_EOL));
            }
        }

        return $this->exitCode;
    }

    /**
     * The demo owns the whole stack for its duration, so it refuses to
     * start against a platform that is already answering.
     */
    private function abortIfPlatformAlreadyRunning(): void
    {
        // Short on purpose: portAnswers() keeps retrying until the deadline,
        // so on the normal path (nothing running) this is pure waiting.
        if (OwnedProcess::portAnswers($this->host, $this->httpPort, self::PORT_PROBE_SECONDS)) {
            throw new RuntimeException(sprintf(
                'The platform is already running on http://%s:%d. Stop the existing serve and run the demo again.',
                $this->host,
                $this->httpPort,
            ));
        }
    }

    /**
     * Starting platform... -> every component answers, probed the way
     * `status` does: HTTP by a real /health answer, database and cache by
     * their ports, the queue by its status route, the pool by its live
     * worker count.
     */
    private function startupSections(): void
    {
        printf("Starting platform...\n");

        $this->serve = OwnedProcess::start([PHP_BINARY, $this->binPath, 'serve'], 'serve', $this->logDir);

        OwnedProcess::waitFor(fn (): bool => $this->get('/health')['status'] === 200, self::SERVE_START_DEADLINE_SECONDS, 'the HTTP server did not come up in time.');
        $this->componentLine('HTTP server', 'OK');

        $this->waitForPort($this->dbPort, 'the database server did not come up in time.');
        $this->componentLine('Database', 'OK');

        $this->waitForPort($this->cachePort, 'the cache server did not come up in time.');
        $this->componentLine('Cache', 'OK');

        $queueUp = $this->get('/queue/status')['status'] === 200;
        $this->componentLine('Queue', $queueUp ? 'OK' : 'FAILED');

        if (!$queueUp) {
            $this->exitCode = 1;
        }

        $active = 0;

        OwnedProcess::waitFor(function () use (&$active): bool {
            try {
                $active = count($this->livePoolPids());
            } catch (RuntimeException) {
                return false; // the pool socket is not accepting yet
            }

            return $active === $this->orderedWorkers;
        }, self::WORKER_START_DEADLINE_SECONDS, 'the worker pool did not come up with all workers in time.');

        $this->componentLine('Workers', (string) $active);

        printf("\n");
    }

    /**
     * Creating orders... -> 100 real POST /orders, each one the platform's
     * write path: a database insert plus an order.created job appended to
     * the durable journal.
     */
    private function createOrders(): void
    {
        printf("Creating orders...\n");

        for ($i = 0; $i < self::ORDER_COUNT; $i++) {
            $response = $this->http->request('POST', '/orders', json_encode([
                'customer' => sprintf('Demo Customer %d', $i),
                'amount' => 10 + ($i % 90),
            ], JSON_THROW_ON_ERROR));

            if ($response['status'] !== 201) {
                throw new RuntimeException(sprintf('POST /orders answered %d: %s', $response['status'], substr($response['body'], 0, 120)));
            }
        }

        printf("Created %d orders\n\n", self::ORDER_COUNT);
    }

    /**
     * Publishing jobs... -> serve filled the journal through its live
     * producer; that journal is what the consumer restores from.
     */
    private function reportPublishedJobs(): void
    {
        printf("Publishing jobs...\n");

        $published = (int) $this->journal()->snapshot()['published'];

        if ($published !== self::ORDER_COUNT) {
            throw new RuntimeException(sprintf('Expected %d published jobs, journal held %d.', self::ORDER_COUNT, $published));
        }

        printf("Published %d jobs\n\n", $published);
    }

    /**
     * Processing... and the per-worker split. queue:consume dispatches the
     * jobs to the pool and keeps per-worker counters in workers.status.json;
     * once the journal shows the batch resolved, that file says who did how
     * much.
     */
    private function processJobs(): void
    {
        printf("Processing...\n");

        $this->consumer = OwnedProcess::start([PHP_BINARY, $this->binPath, 'queue:consume'], 'consumer', $this->logDir);

        OwnedProcess::waitFor(function (): bool {
            $snapshot = $this->journal()->snapshot();

            return (int) $snapshot['completed'] >= self::ORDER_COUNT
                && (int) $snapshot['depth'] === 0;
        }, self::WORKER_DRAIN_DEADLINE_SECONDS, 'the queue consumer did not finish the batch in time.');

        // The status file is written on the consumer's own loop, so it can
        // trail the journal by a tick.
        $rows = [];

        OwnedProcess::waitFor(function () use (&$rows): bool {
            $rows = $this->workerRows();

            return array_sum(array_column($rows, 'tasks_completed')) >= self::ORDER_COUNT;
        }, 5.0, 'the consumer never wrote its per-worker lifecycle back.');

        usort($rows, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);

        foreach ($rows as $row) {
            printf("Worker #%d processed %d jobs\n", $row['id'], $row['tasks_completed']);
        }

        $allocated = array_sum(array_column($rows, 'tasks_completed'));

        if ($allocated !== self::ORDER_COUNT) {
            throw new RuntimeException(sprintf('Processing accounted for %d of %d jobs.', $allocated, self::ORDER_COUNT));
        }

        printf("\n");
    }

    /**
     * Injecting failure... and Recovering... -> POST /debug/fail-worker
     * crashes one pool worker on a real task; the injector's report (the
     * pool Master's own bookkeeping) proves each phase, and a fresh stats()
     * read proves the pool is back at its configured size.
     */
    private function injectFailure(): void
    {
        $before = $this->livePoolPids();

        printf("Injecting failure...\n");

        $response = $this->http->request('POST', '/debug/fail-worker');

        if ($response['status'] !== 200) {
            throw new RuntimeException(sprintf('POST /debug/fail-worker answered %d: %s', $response['status'], substr($response['body'], 0, 120)));
        }

        $report = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($report)) {
            throw new RuntimeException('POST /debug/fail-worker returned an unreadable report.');
        }

        if (!($report['crash_detected'] ?? false) || !($report['replacement_started'] ?? false)) {
            throw new RuntimeException('The worker did not crash and get replaced: ' . (string) json_encode($report));
        }

        // Workers are labelled by their position in the pre-crash list; the
        // replacement inherits the crashed worker's label.
        $crashedPid = (int) ($report['crashed_pid'] ?? 0);
        $crashedIndex = array_search($crashedPid, $before, true);
        $label = $crashedIndex === false ? 0 : $crashedIndex + 1;

        printf("Worker #%d (pid %d) exited unexpectedly\n", $label, $crashedPid);
        printf("  detected %.1f ms after the crash, replacement within %.1f ms\n", (float) ($report['detected_ms'] ?? 0.0), (float) ($report['replaced_ms'] ?? 0.0));

        printf("\nRecovering...\n");
        printf("Worker #%d (pid %d) restarted\n", $label, (int) ($report['replacement_pid'] ?? 0));

        $after = count($this->livePoolPids());

        if ($after !== $this->orderedWorkers) {
            throw new RuntimeException(sprintf('The pool stayed at %d workers instead of recovering to %d.', $after, $this->orderedWorkers));
        }

        printf("  pool back at %d/%d workers\n\n", $after, $this->orderedWorkers);
    }

    /**
     * Retrying failed jobs... -> the running consumer's real retry loop,
     * observed through the journal: a demo.failing job fails on every
     * delivery, is retried per policy, and retires into FAILED once its
     * attempt budget runs out.
     */
    private function demonstrateRetry(): void
    {
        printf("Retrying failed jobs...\n");

        $job = JournalOnlyQueue::producer($this->queueLog)
            ->dispatch(FailingJob::TYPE, [], maxAttempts: (int) $this->config['queue']['max_attempts']);

        printf("  published demo.failing (%s, max %d attempts)\n", $job->getId(), $job->getMaxAttempts());

        $id = $job->getId()->toString();
        $lastAttempts = 0;
        $terminal = '';

        OwnedProcess::waitForQuietly(function () use ($id, &$lastAttempts, &$terminal): bool {
            $row = $this->journal()->rows()[$id] ?? [];
            $attempts = (int) ($row['attempts'] ?? 0);

            // One line per attempt, even if two landed between polls.
            while ($lastAttempts < $attempts) {
                printf("  attempt %d -> failed\n", ++$lastAttempts);
            }

            $terminal = (string) ($row['state'] ?? '');

            return $terminal === 'FAILED' || $terminal === 'COMPLETED';
        }, self::RETRY_POLL_DEADLINE_SECONDS);

        if ($terminal !== 'FAILED') {
            throw new RuntimeException(sprintf('The failing job did not retire: terminal state was "%s".', $terminal));
        }

        if ($lastAttempts !== $job->getMaxAttempts()) {
            throw new RuntimeException(sprintf('Expected %d attempts, the journal recorded %d.', $job->getMaxAttempts(), $lastAttempts));
        }

        printf(
            "  => after %d attempts the job was retired into FAILED (retried=%d)\n\n",
            $job->getMaxAttempts(),
            (int) $this->journal()->snapshot()['retried'],
        );
    }

    /**
     * Final statistics... -> the live /metrics snapshot, read one more time
     * before anything is stopped.
     */
    private function finalStatistics(): void
    {
        printf("Final statistics...\n");

        $m = array_map(intval(...), $this->http->metrics());

        printf("  HTTP      %d requests, %d errors\n", $m['http.requests'] ?? 0, $m['http.errors'] ?? 0);
        printf(
            "  Cache     %d operations (%d hits, %d misses)\n",
            $m['cache.operations'] ?? 0,
            $m['cache.hit'] ?? 0,
            $m['cache.miss'] ?? 0,
        );
        printf("  Database  %d operations\n", $m['db.operations'] ?? 0);
        printf(
            "  Queue     %d published, %d completed, %d failed, %d retried\n",
            $m['queue.published'] ?? 0,
            $m['queue.completed'] ?? 0,
            $m['queue.failed'] ?? 0,
            $m['queue.retried'] ?? 0,
        );
        printf(
            "  Workers   %d active, %d busy, %d idle\n",
            $m['workers.active'] ?? 0,
            $m['workers.busy'] ?? 0,
            $m['workers.idle'] ?? 0,
        );
        printf(
            "  Memory    master %s, workers %s each\n",
            $this->formatMegabytes($m['process.rss'] ?? 0),
            $this->formatMegabytes($m['worker.rss'] ?? 0),
        );

        if (($m['queue.completed'] ?? 0) < self::ORDER_COUNT || ($m['queue.failed'] ?? 0) < 1) {
            $this->exitCode = 1;
        }

        printf("\n");
    }

    /**
     * Graceful shutdown... -> SIGTERM both processes, the signal a deploy
     * sends. A zero exit is not enough: each child's own "stopped" line
     * (printed only after it drained) is what proves the shutdown was
     * graceful rather than merely quick.
     */
    private function gracefulShutdown(): void
    {
        printf("Graceful shutdown...\n");

        $consumerExit = $this->stopChild($this->consumer, 'queue:consume');
        $this->componentLine('queue consumer', $consumerExit === 0 ? 'stopped gracefully' : 'failed to stop');

        $serveExit = $this->stopChild($this->serve, 'serve');
        $this->componentLine('platform serve', $serveExit === 0 ? 'stopped gracefully' : 'failed to stop');

        if ($consumerExit !== 0 || $serveExit !== 0
            || !str_contains($this->consumer?->output() ?? '', 'Consumer stopped.')
            || !str_contains($this->serve?->output() ?? '', 'Shutdown complete.')) {
            $this->exitCode = 1;
        }

        printf("All services stopped.\n");
    }

    private function stopChild(?OwnedProcess $child, string $name): int
    {
        $code = $child?->stop(self::CHILD_STOP_DEADLINE_SECONDS) ?? 0;

        if ($code !== 0) {
            fwrite(STDERR, sprintf('The %s process exited with code %d%s', $name, $code, PHP_EOL));
        }

        return $code;
    }

    /**
     * Pids of the pool's non-DEAD workers, in the order stats() lists them.
     *
     * @return list<int>
     *
     * @throws RuntimeException the pool is not reachable
     */
    private function livePoolPids(): array
    {
        $pids = [];

        foreach (new WorkerPoolClient($this->poolSocket, 3.0)->stats() as $worker) {
            if ((string) $worker['state'] !== 'DEAD') {
                $pids[] = (int) $worker['pid'];
            }
        }

        return $pids;
    }

    /**
     * @return list<array{id: int, tasks_completed: int}>
     */
    private function workerRows(): array
    {
        $encoded = is_file($this->workersStatusFile) ? (string) file_get_contents($this->workersStatusFile) : '[]';
        $rows = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
        $normalized = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row)) {
                $normalized[] = [
                    'id' => (int) ($row['id'] ?? 0),
                    'tasks_completed' => (int) ($row['tasks_completed'] ?? 0),
                ];
            }
        }

        return $normalized;
    }

    /**
     * @return array{status: int, body: string, headers: array<string, string>}
     */
    private function get(string $path): array
    {
        return $this->http->request('GET', $path);
    }

    private function waitForPort(int $port, string $timeoutMessage): void
    {
        if (!OwnedProcess::portAnswers($this->host, $port, self::COMPONENT_START_DEADLINE_SECONDS)) {
            throw new RuntimeException($timeoutMessage);
        }
    }

    private function componentLine(string $label, string $value): void
    {
        printf("  %-23s %s\n", str_pad($label, 23, '.'), $value);
    }

    private function formatMegabytes(int $bytes): string
    {
        return $bytes <= 0 ? 'n/a' : sprintf('%.1fM', $bytes / (1024 * 1024));
    }

    private function journal(): QueueJournal
    {
        return new QueueJournal($this->queueLog);
    }
}
