<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Demo;

use PhpJobQueue\Metrics\MetricsCollector;
use PhpJobQueue\Persistence\FileStorage;
use PhpJobQueue\Producer\JobFactory;
use PhpJobQueue\Producer\Producer;
use PhpJobQueue\Queue\InMemoryQueue;
use PhpJobQueue\Support\Clock;
use PhpJobQueue\Support\SystemClock;
use PhpSystemsPlatform\Queue\Jobs\FailingJob;
use PhpSystemsPlatform\Queue\QueueJournal;
use PhpWorkerPool\IPC\ConnectionClosedException;
use PhpWorkerPool\Sdk\ConnectionFailedException;
use PhpWorkerPool\Sdk\ServerErrorException;
use PhpWorkerPool\Sdk\WorkerPoolClient;
use RuntimeException;

/**
 * PLAN Step 26: the whole platform told as one story, run against the real
 * binaries and the real ports.
 *
 * The demo drives the platform the way an operator would, so every line is
 * backed by a live fact: a serve process and a queue:consume process, 100
 * orders over real HTTP, the journal proving 100 published jobs, a pool
 * worker crashed through POST /debug/fail-worker and replaced by the pool
 * Master, a demo.failing job retried by the running consumer until it spends
 * its attempt budget, a final /metrics snapshot, and a graceful shutdown
 * verified from each child's own "stopped" line.
 */
final class PlatformDemo
{
    private const int ORDER_COUNT = 100;

    private const float STATIC_PROBE_TIMEOUT_SECONDS = 5.0;

    private const float SERVE_START_DEADLINE_SECONDS = 25.0;

    private const float WORKER_START_DEADLINE_SECONDS = 30.0;

    private const float WORKER_DRAIN_DEADLINE_SECONDS = 120.0;

    private const float RETRY_POLL_DEADLINE_SECONDS = 30.0;

    private const float CHILD_STOP_DEADLINE_SECONDS = 20.0;

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

    private readonly Clock $clock;

    private int $exitCode = 0;

    /** @var list<array{proc: resource, name: string, stdout: string, stderr: string}> */
    private array $children = [];

    public function __construct()
    {
        $this->config = require dirname(__DIR__, 2) . '/config/platform.php';

        $http = (array) $this->config['http'];
        $database = (array) $this->config['database'];
        $cache = (array) $this->config['cache'];
        $queue = (array) $this->config['queue'];
        $workers = (array) $this->config['workers'];

        $this->host = (string) $http['host'];
        $this->httpPort = (int) $http['port'];
        $this->dbPort = (int) $database['port'];
        $this->cachePort = (int) $cache['port'];
        $this->queueLog = (string) $queue['data_dir'] . '/queue.log';
        $this->workersStatusFile = (string) $workers['data_dir'] . '/workers.status.json';
        $this->poolSocket = (string) $workers['socket'];
        $this->dataDir = dirname((string) $database['data_dir']);
        $this->binPath = dirname(__DIR__, 2) . '/bin/platform.php';
        $this->logDir = sys_get_temp_dir() . '/php-systems-platform-demo-' . uniqid('', true);
        $this->orderedWorkers = (int) $workers['count'];
        $this->clock = new SystemClock();
    }

    public function run(): int
    {
        try {
            $this->abortIfPlatformAlreadyRunning();
            $this->stopStaleDatabaseServer();
            $this->removeTree($this->dataDir);
            mkdir($this->logDir, 0o777, true);

            // The pool Master autoscales between its configured floor and
            // ceiling, so the platform would open with just two workers until
            // the first burst. The demo tunes the floor to the platform's
            // declared size (workers.count) so the story can point at a real
            // pool of that exact size, start to finish.
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
            $this->terminateChildren();

            if ($this->exitCode === 0 && is_dir($this->logDir)) {
                $this->removeTree($this->logDir);
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
        if ($this->portAnswers($this->httpPort, self::STATIC_PROBE_TIMEOUT_SECONDS)) {
            throw new RuntimeException(sprintf(
                'The platform is already running on http://%s:%d. Stop the existing serve and run the demo again.',
                $this->host,
                $this->httpPort,
            ));
        }
    }

    /**
     * A crashed serve can leave its daemonized database server behind. Its
     * pid file is the unambiguous owner, read before the data directory is
     * wiped for a fresh platform.
     */
    private function stopStaleDatabaseServer(): void
    {
        if (!$this->portAnswers($this->dbPort, 0.5)) {
            return;
        }

        $pidFile = $this->dataDir . '/db/minidb.pid';

        $pid = is_file($pidFile) ? (int) trim((string) file_get_contents($pidFile)) : 0;

        if ($pid > 0) {
            posix_kill($pid, SIGTERM);
            $this->waitFor(function () use ($pid): bool {
                return !posix_kill($pid, 0);
            }, self::CHILD_STOP_DEADLINE_SECONDS, 'the stale database server did not stop in time.');
        }
    }

    /**
     * Starting platform... -> every component of this serve answers, probed
     * exactly the way `status` does: the HTTP one by a real /health answer,
     * database and cache by their ports, the queue by its own status route,
     * the pool by the worker count it reports.
     */
    private function startupSections(): void
    {
        printf("Starting platform...\n");

        $serve = $this->startChild('serve', [PHP_BINARY, $this->binPath, 'serve'], 'serve.out', 'serve.err');

        $this->waitFor(fn (): bool => $this->healthAnswers(), self::SERVE_START_DEADLINE_SECONDS, 'the HTTP server did not come up in time.');
        $this->componentLine('HTTP server', 'OK');

        $this->waitFor(fn (): bool => $this->portAnswers($this->dbPort, 0.3), self::STATIC_PROBE_TIMEOUT_SECONDS, 'the database server did not come up in time.');
        $this->componentLine('Database', 'OK');

        $this->waitFor(fn (): bool => $this->portAnswers($this->cachePort, 0.3), self::STATIC_PROBE_TIMEOUT_SECONDS, 'the cache server did not come up in time.');
        $this->componentLine('Cache', 'OK');

        [$status, ] = $this->http('GET', '/queue/status');
        $this->componentLine('Queue', $status === 200 ? 'OK' : 'FAILED');

        if ($status !== 200) {
            $this->exitCode = 1;
        }

        $workers = null;

        $this->waitFor(function () use (&$workers): bool {
            $workers = $this->poolStats();

            return $workers !== null && (int) $workers['active'] === $this->orderedWorkers;
        }, self::WORKER_START_DEADLINE_SECONDS, 'the worker pool did not come up with all workers in time.');

        $this->componentLine('Workers', (string) $workers['active']);

        printf("\n");
    }

    /**
     * Creating orders... -> 100 real POST /orders over HTTP, each one the
     * platform's write path: database insert plus an order.created job
     * appended to the durable journal.
     */
    private function createOrders(): void
    {
        printf("Creating orders...\n");

        $created = 0;

        for ($i = 0; $i < self::ORDER_COUNT; $i++) {
            [$status, $body] = $this->http('POST', '/orders', json_encode([
                'customer' => sprintf('Demo Customer %d', $i),
                'amount' => 10 + ($i % 90),
            ], JSON_THROW_ON_ERROR));

            if ($status !== 201) {
                $this->exitCode = $this->exitCode === 0 ? 1 : $this->exitCode;
                $body = (string) $body;
                throw new RuntimeException(sprintf('POST /orders answered %d: %s', $status, substr($body, 0, 120)));
            }

            $created++;
        }

        printf("Created %d orders\n\n", $created);
    }

    /**
     * Publishing jobs... -> the journal, which serve filled through the live
     * producer, is the artifact a consumer restores - depth is the number of
     * background jobs waiting behind the batch of orders.
     */
    private function reportPublishedJobs(): void
    {
        printf("Publishing jobs...\n");

        $snapshot = $this->journal()->snapshot();
        $published = (int) $snapshot['published'];

        if ($published !== self::ORDER_COUNT) {
            throw new RuntimeException(sprintf('Expected %d published jobs, journal held %d.', self::ORDER_COUNT, $published));
        }

        printf("Published %d jobs\n\n", $published);
    }

    /**
     * Processing... and the per-worker split. The queue:consume process owns
     * the queue side (forwarders dispatched to the pool) and keeps a running
     * per-worker lifecycle in workers.status.json; when the journal shows the
     * whole batch resolved, that file is the truth about who did how much.
     */
    private function processJobs(): void
    {
        printf("Processing...\n");

        $this->startChild('queue:consume', [PHP_BINARY, $this->binPath, 'queue:consume'], 'consumer.out', 'consumer.err');

        $this->waitFor(function (): bool {
            $snapshot = $this->journal()->snapshot();

            return (int) $snapshot['completed'] >= self::ORDER_COUNT
                && (int) $snapshot['depth'] === 0;
        }, self::WORKER_DRAIN_DEADLINE_SECONDS, 'the queue consumer did not finish the batch in time.');

        $captured = false;

        $this->waitFor(function () use (&$captured): bool {
            $rows = $this->workerRows();
            $total = array_sum(array_map(static fn (array $row): int => (int) $row['tasks_completed'], $rows));
            $captured = $total >= self::ORDER_COUNT;

            return $captured;
        }, 5.0, 'the consumer never wrote its per-worker lifecycle back.');

        $rows = $this->workerRows();
        usort($rows, static fn (array $a, array $b): int => (int) $a['id'] <=> (int) $b['id']);

        $allocated = 0;

        foreach ($rows as $row) {
            $done = (int) $row['tasks_completed'];
            $allocated += $done;
            printf("Worker #%d processed %d jobs\n", (int) $row['id'], $done);
        }

        if ($allocated !== self::ORDER_COUNT) {
            throw new RuntimeException(sprintf('Processing accounted for %d of %d jobs.', $allocated, self::ORDER_COUNT));
        }

        printf("\n");
    }

    /**
     * Injecting failure... and Recovering... -> POST /debug/fail-worker
     * crashes one pool worker on a real task; the pool Master's bookkeeping
     * (the injector's report) proves every phase, and a fresh stats() read
     * proves the pool came back to its configured size.
     */
    private function injectFailure(): void
    {
        $client = new WorkerPoolClient($this->poolSocket, 5.0);
        $before = $this->poolPids($client);

        printf("Injecting failure...\n");

        [$status, $body] = $this->http('POST', '/debug/fail-worker');

        if ($status !== 200) {
            throw new RuntimeException(sprintf('POST /debug/fail-worker answered %d: %s', $status, substr((string) $body, 0, 120)));
        }

        $report = json_decode((string) $body, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($report)) {
            throw new RuntimeException('POST /debug/fail-worker returned an unreadable report.');
        }

        if (!($report['crash_detected'] ?? false) || !($report['replacement_started'] ?? false)) {
            throw new RuntimeException('The worker did not crash and get replaced: ' . (string) json_encode($report));
        }

        $crashedIndex = array_search((int) ($report['crashed_pid'] ?? 0), $before, true);
        $label = $crashedIndex === false ? 0 : $crashedIndex + 1;

        printf("Worker #%d (pid %d) exited unexpectedly\n", $label, (int) ($report['crashed_pid'] ?? 0));
        printf("  detected %.1f ms after the crash, replacement within %.1f ms\n", (float) ($report['detected_ms'] ?? 0.0), (float) ($report['replaced_ms'] ?? 0.0));

        printf("\nRecovering...\n");
        printf("Worker #%d (pid %d) restarted\n", $label, (int) ($report['replacement_pid'] ?? 0));

        $after = $this->poolPids($client);

        if (count($after) !== $this->orderedWorkers) {
            throw new RuntimeException(sprintf('The pool stayed at %d workers instead of recovering to %d.', count($after), $this->orderedWorkers));
        }

        printf("  pool back at %d/%d workers\n\n", count($after), $this->orderedWorkers);
    }

    /**
     * Retrying failed jobs... -> the running consumer's real retry loop,
     * observed from the durable journal: a demo.failing job published into
     * the same log fails on every delivery, is retried per policy after each
     * failure, and retires into FAILED once its attempt budget runs out.
     */
    private function demonstrateRetry(): void
    {
        printf("Retrying failed jobs...\n");

        $job = new Producer(
            new InMemoryQueue($this->clock, new FileStorage($this->queueLog)),
            new JobFactory($this->clock, new MetricsCollector()),
        )->dispatch(FailingJob::TYPE, [], maxAttempts: (int) $this->config['queue']['max_attempts']);

        printf("  published demo.failing (%s, max %d attempts)\n", $job->getId(), $job->getMaxAttempts());

        $id = $job->getId()->toString();
        $lastAttempts = 0;
        $deadline = microtime(true) + self::RETRY_POLL_DEADLINE_SECONDS;
        $terminal = '';

        while (microtime(true) < $deadline) {
            $row = $this->journal()->rows()[$id] ?? [];
            $state = (string) ($row['state'] ?? '');
            $attempts = (int) ($row['attempts'] ?? 0);

            if ($attempts !== $lastAttempts) {
                printf("  attempt %d -> failed\n", $attempts);
                $lastAttempts = $attempts;
            }

            if ($state === 'FAILED' || $state === 'COMPLETED') {
                $terminal = $state;
                break;
            }

            usleep(20_000);
        }

        if ($terminal !== 'FAILED') {
            throw new RuntimeException(sprintf('The failing job did not retire: terminal state was "%s".', $terminal));
        }

        if ($lastAttempts !== $job->getMaxAttempts()) {
            throw new RuntimeException(sprintf('Expected %d attempts, the journal recorded %d.', $job->getMaxAttempts(), $lastAttempts));
        }

        $retried = (int) $this->journal()->snapshot()['retried'];

        printf(
            "  => after %d attempts the job was retired into FAILED (retried=%d)\n\n",
            $job->getMaxAttempts(),
            $retried,
        );
    }

    /**
     * Final statistics... -> the live /metrics snapshot the serve exposes,
     * read one more time before anything is stopped.
     */
    private function finalStatistics(): void
    {
        printf("Final statistics...\n");

        $metrics = $this->metrics();

        (function (array $m): void {
            printf(
                "  HTTP      %d requests, %d errors\n",
                (int) ($m['http.requests'] ?? 0),
                (int) ($m['http.errors'] ?? 0),
            );
            printf(
                "  Cache     %d operations (%d hits, %d misses)\n",
                (int) ($m['cache.operations'] ?? 0),
                (int) ($m['cache.hit'] ?? 0),
                (int) ($m['cache.miss'] ?? 0),
            );
            printf(
                "  Database  %d operations\n",
                (int) ($m['db.operations'] ?? 0),
            );
            printf(
                "  Queue     %d published, %d completed, %d failed, %d retried\n",
                (int) ($m['queue.published'] ?? 0),
                (int) ($m['queue.completed'] ?? 0),
                (int) ($m['queue.failed'] ?? 0),
                (int) ($m['queue.retried'] ?? 0),
            );
            printf(
                "  Workers   %d active, %d busy, %d idle\n",
                (int) ($m['workers.active'] ?? 0),
                (int) ($m['workers.busy'] ?? 0),
                (int) ($m['workers.idle'] ?? 0),
            );
            printf(
                "  Memory    master %s, workers %s each\n",
                $this->formatMegabytes((int) ($m['process.rss'] ?? 0)),
                $this->formatMegabytes((int) ($m['worker.rss'] ?? 0)),
            );
        })($metrics);

        if ((int) ($metrics['queue.completed'] ?? 0) < self::ORDER_COUNT || (int) ($metrics['queue.failed'] ?? 0) < 1) {
            $this->exitCode = $this->exitCode === 0 ? 1 : $this->exitCode;
        }

        printf("\n");
    }

    /**
     * Graceful shutdown... -> SIGTERM both processes, the same signal a
     * deploy sends, and judge the shutdown by each child's own "stopped"
     * line: the consumer prints one after draining its workers, the serve
     * after closing every service it owns.
     */
    private function gracefulShutdown(): void
    {
        printf("Graceful shutdown...\n");

        $consumer = $this->children[1] ?? null;
        $consumerExit = $consumer !== null ? $this->stopChildGracefully($consumer) : 0;
        $this->componentLine('queue consumer', $consumerExit === 0 ? 'stopped gracefully' : 'failed to stop');

        $serve = $this->children[0] ?? null;
        $serveExit = $serve !== null ? $this->stopChildGracefully($serve) : 0;
        $this->componentLine('platform serve', $serveExit === 0 ? 'stopped gracefully' : 'failed to stop');

        if ($consumerExit !== 0 || $serveExit !== 0) {
            $this->exitCode = $this->exitCode === 0 ? 1 : $this->exitCode;
        }

        // Each child's own "stopped" line is the argument that the shutdown
        // really was graceful - the reports they print as they drain.
        $consumerReport = $consumer !== null ? (string) file_get_contents($consumer['stdout']) : '';

        if (!str_contains($consumerReport, 'Consumer stopped.')) {
            $this->exitCode = $this->exitCode === 0 ? 1 : $this->exitCode;
        }

        $serveReport = $serve !== null ? (string) file_get_contents($serve['stdout']) : '';

        if (!str_contains($serveReport, 'Shutdown complete.')) {
            $this->exitCode = $this->exitCode === 0 ? 1 : $this->exitCode;
        }

        printf("All services stopped.\n");
    }

    /**
     * The pool's current worker pids, one list for the crash labels and the
     * recovery check.
     *
     * @return list<int>
     */
    private function poolPids(WorkerPoolClient $client): array
    {
        $stats = $client->stats();
        $pids = [];

        foreach ($stats as $worker) {
            if ((string) $worker['state'] !== 'DEAD') {
                $pids[] = (int) $worker['pid'];
            }
        }

        return $pids;
    }

    /** @return array{active: int, busy: int, idle: int}|null */
    private function poolStats(): ?array
    {
        try {
            $stats = $this->poolClient()->stats();
        } catch (ConnectionFailedException | ConnectionClosedException | ServerErrorException) {
            return null;
        }

        $active = 0;
        $busy = 0;
        $idle = 0;

        foreach ($stats as $worker) {
            if ((string) $worker['state'] === 'DEAD') {
                continue;
            }

            $active++;

            if ((string) $worker['state'] === 'BUSY') {
                $busy++;
            } elseif ((string) $worker['state'] === 'IDLE') {
                $idle++;
            }
        }

        return ['active' => $active, 'busy' => $busy, 'idle' => $idle];
    }

    /**
     * @return list<array{id: int, pid: int, tasks_completed: int, tasks_failed: int}>
     */
    private function workerRows(): array
    {
        $encoded = is_file($this->workersStatusFile) ? (string) file_get_contents($this->workersStatusFile) : '[]';
        $rows = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($rows)) {
            return [];
        }

        $normalized = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $normalized[] = [
                'id' => (int) ($row['id'] ?? 0),
                'pid' => (int) ($row['pid'] ?? 0),
                'tasks_completed' => (int) ($row['tasks_completed'] ?? 0),
                'tasks_failed' => (int) ($row['tasks_failed'] ?? 0),
            ];
        }

        return $normalized;
    }

    /**
     * @return array<string, int|float>
     */
    private function metrics(): array
    {
        [$status, $body] = $this->http('GET', '/metrics');

        if ($status !== 200) {
            throw new RuntimeException(sprintf('GET /metrics answered %d.', $status));
        }

        $metrics = [];

        foreach (explode("\n", (string) $body) as $line) {
            if (preg_match('/^(\S+)\s+(\S+)$/', trim($line), $matches) !== 1) {
                continue;
            }

            $value = is_numeric($matches[2]) ? (float) $matches[2] : 0.0;
            $metrics[$matches[1]] = $value;
        }

        return $metrics;
    }

    /**
     * @return array{int, string} HTTP status and body
     */
    private function http(string $method, string $path, ?string $body = null): array
    {
        $options = [
            'http' => [
                'method' => $method,
                'header' => 'Content-Type: application/json',
                'content' => $body,
                'timeout' => 8.0,
                'ignore_errors' => true,
            ],
        ];

        $http_response_header = [];

        $response = @file_get_contents(
            sprintf('http://%s:%d%s', $this->host, $this->httpPort, $path),
            false,
            stream_context_create($options),
        );

        $status = 0;

        foreach ($http_response_header as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $matches) === 1) {
                $status = (int) $matches[1];
            }
        }

        return [$status, (string) $response];
    }

    private function healthAnswers(): bool
    {
        [$status] = $this->http('GET', '/health');

        return $status === 200;
    }

    private function portAnswers(int $port, float $timeoutSeconds): bool
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            $socket = @stream_socket_client(sprintf('tcp://%s:%d', $this->host, $port), $code, $message, 0.2);

            if ($socket !== false) {
                fclose($socket);

                return true;
            }

            usleep(50_000);
        }

        return false;
    }

    private function waitFor(\Closure $probe, float $deadlineSeconds, string $timeoutMessage): void
    {
        $deadline = microtime(true) + $deadlineSeconds;

        while (microtime(true) < $deadline) {
            if ($probe()) {
                return;
            }

            usleep(50_000);
        }

        throw new RuntimeException($timeoutMessage);
    }

    /**
     * @param list<string> $argv
     *
     * @return resource
     */
    private function startChild(string $name, array $argv, string $stdoutFile, string $stderrFile)
    {
        /** @var resource $proc */
        $proc = proc_open(
            $argv,
            [
                1 => ['file', $this->logDir . '/' . $stdoutFile, 'a'],
                2 => ['file', $this->logDir . '/' . $stderrFile, 'a'],
            ],
            $pipes,
        );

        if (!is_resource($proc)) {
            throw new RuntimeException(sprintf('Could not start the %s process.', $name));
        }

        $this->children[] = [
            'proc' => $proc,
            'name' => $name,
            'stdout' => $this->logDir . '/' . $stdoutFile,
            'stderr' => $this->logDir . '/' . $stderrFile,
        ];

        return $proc;
    }

    /**
     * @param array{proc: resource, name: string, stdout: string, stderr: string} $child
     */
    private function stopChildGracefully(array $child): int
    {
        proc_terminate($child['proc']);
        $code = $this->waitForExit($child['proc'], self::CHILD_STOP_DEADLINE_SECONDS);

        if ($code === null) {
            proc_terminate($child['proc'], 9);
            $code = $this->waitForExit($child['proc'], 5.0) ?? -1;
        }

        if ($code !== 0) {
            fwrite(STDERR, sprintf('The %s process exited with code %s%s', $child['name'], (string) $code, PHP_EOL));
        }

        return $code;
    }

    /** @param resource $proc */
    private function waitForExit($proc, float $deadlineSeconds): ?int
    {
        $deadline = microtime(true) + $deadlineSeconds;

        while (microtime(true) < $deadline) {
            $status = proc_get_status($proc);

            if (!$status['running']) {
                return $status['exitcode'];
            }

            usleep(50_000);
        }

        return null;
    }

    private function terminateChildren(): void
    {
        foreach ($this->children as $child) {
            if (is_resource($child['proc'])) {
                proc_terminate($child['proc']);
            }
        }

        foreach ($this->children as $child) {
            $this->waitForExit($child['proc'], 3.0);
        }

        foreach ($this->children as $child) {
            if (is_resource($child['proc'])) {
                $status = proc_get_status($child['proc']);

                if ($status['running']) {
                    proc_terminate($child['proc'], 9);
                }

                proc_close($child['proc']);
            }
        }

        $this->children = [];

        if (is_dir($this->logDir)) {
            $this->removeTree($this->logDir);
        }
    }

    private function componentLine(string $label, string $value): void
    {
        printf("  %-23s %s\n", str_pad($label, 23, '.'), $value);
    }

    private function formatMegabytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return 'n/a';
        }

        return sprintf('%.1fM', $bytes / (1024 * 1024));
    }

    private function journal(): QueueJournal
    {
        return new QueueJournal($this->queueLog);
    }

    private function poolClient(): WorkerPoolClient
    {
        return new WorkerPoolClient($this->poolSocket, 3.0);
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }

        @rmdir($dir);
    }
}
