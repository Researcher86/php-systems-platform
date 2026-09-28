<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Experiments;

use PhpJobQueue\Metrics\MetricsCollector;
use PhpJobQueue\Persistence\FileStorage;
use PhpJobQueue\Producer\JobFactory;
use PhpJobQueue\Producer\Producer;
use PhpJobQueue\Queue\InMemoryQueue;
use PhpJobQueue\Support\SystemClock;
use PhpSystemsPlatform\Support\HttpProbe;
use PhpSystemsPlatform\Support\OwnedProcess;
use PhpWorkerPool\IPC\ConnectionClosedException;
use PhpWorkerPool\Sdk\ConnectionFailedException;
use PhpWorkerPool\Sdk\ServerErrorException;
use PhpWorkerPool\Sdk\WorkerPoolClient;
use RuntimeException;

/**
 * PLAN Step 30's five failure/overload experiments, run against a platform
 * this command owns and stops:
 *
 *   1. slow workers - a pool that is the bottleneck lets the queue grow
 *   2. worker crash - the pool manager detects it and replaces the worker
 *   3. cache down   - every read bypasses to the database, still 200
 *   4. slow database- request latency tracks the configured database delay
 *   5. queue full   - the producer is told to retry, not left to block
 *
 * Each experiment restarts the platform with the small piece of configuration
 * it needs (a fixed pool, a latency, a small queue), so the five are each
 * reproducible on their own and the whole command is one pass over the two
 * failure axes the PLAN names. Everything the experiments observe is what the
 * platform already reports - /metrics, /queue/status, /workers, and the
 * X-Cache header - rather than a second, experiment-only instrumentation.
 *
 * The command owns its platform exactly like the load run does: it refuses to
 * start on a port already being served, wipes the platform's data directory,
 * starts a serve it holds a handle to, and stops it - and every process the
 * serve started for it - before returning, on every path.
 */
final class FailureExperiments
{
    private const float PROBE_INTERVAL_SECONDS = 0.2;

    private const float RECOVERY_DEADLINE_SECONDS = 15.0;

    private const float DRAIN_DEADLINE_SECONDS = 25.0;

    /** @var array<string, mixed> */
    private readonly array $config;

    private readonly string $host;

    private readonly int $port;

    private readonly string $queueLog;

    private HttpProbe $http;

    private string $logDir = '';

    private ?OwnedProcess $serve = null;

    private ?OwnedProcess $consume = null;

    public function __construct()
    {
        /** @var array<string, mixed> $config */
        $config = require dirname(__DIR__, 2) . '/config/platform.php';

        $this->config = $config;
        $this->host = (string) $config['http']['host'];
        $this->port = (int) $config['http']['port'];
        $this->queueLog = $config['queue']['data_dir'] . '/queue.log';
        $this->http = new HttpProbe(sprintf('http://%s:%d', $this->host, $this->port));
    }

    public function run(): int
    {
        $this->refuseIfPlatformAlreadyRunning();

        $this->logDir = sys_get_temp_dir() . '/php-systems-platform-experiments-' . uniqid('', true);
        OwnedProcess::mkdir($this->logDir);

        try {
            $this->experimentSlowWorkers();
            $this->experimentWorkerCrash();
            $this->experimentCacheDown();
            $this->experimentSlowDatabase();
            $this->experimentQueueFull();
        } finally {
            $this->stopServe();
            OwnedProcess::removeTree($this->logDir);
        }

        return 0;
    }

    private function experimentSlowWorkers(): void
    {
        $this->startServe([
            'WORKER_POOL_MIN' => '2',
            'WORKER_POOL_MAX' => '2',
        ]);

        $this->heading('1. Slow workers: the pool is the bottleneck, so the queue grows');

        $this->publishSlowJobs(8, 1.0);

        $history = [];
        $peak = 0;
        $deadline = microtime(true) + self::DRAIN_DEADLINE_SECONDS;

        while (microtime(true) < $deadline) {
            $depth = $this->queueDepth();
            $history[] = $depth;
            $peak = max($peak, $depth);

            if ($depth === 0 && count($history) > 2) {
                break;
            }

            usleep((int) (self::PROBE_INTERVAL_SECONDS * 1_000_000));
        }

        $this->row('published', '8 x demo.slow (1.0s each)');
        $this->row('pool', '2 fixed workers');
        $this->row('depth over time', $this->compressHistory($history));
        $this->row('peak depth', (string) $peak);
        $this->row('drained', $this->queueDepth() === 0 ? 'yes' : 'no');
        $this->row('observed', $peak >= 7
            ? 'depth rose to the backlog while the pool drained it one slow job at a time'
            : sprintf('depth peaked at %d before the pool caught up', $peak));

        $this->stopServe();
    }

    private function experimentWorkerCrash(): void
    {
        $this->startServe([
            'WORKER_POOL_MIN' => '2',
            'WORKER_POOL_MAX' => '2',
        ]);

        $this->heading('2. A crashed worker is detected and replaced');

        $before = $this->workerPids();
        $this->row('worker pids before', implode(', ', $before) ?: 'none');

        $response = $this->http->request('POST', '/debug/fail-worker');
        $this->row('POST /debug/fail-worker', sprintf('HTTP %d', $response['status']));

        $replacement = null;
        $crashedPid = null;
        $deadline = microtime(true) + self::RECOVERY_DEADLINE_SECONDS;

        while (microtime(true) < $deadline) {
            $current = $this->workerPids();
            $crashedPid ??= array_values(array_diff($before, $current))[0] ?? null;
            $replacement ??= array_values(array_diff($current, $before))[0] ?? null;

            if ($crashedPid !== null && $replacement !== null) {
                break;
            }

            usleep((int) (self::PROBE_INTERVAL_SECONDS * 1_000_000));
        }

        $this->row('crashed pid', $crashedPid === null ? 'not observed' : (string) $crashedPid);
        $this->row('replacement pid', $replacement === null ? 'not observed' : (string) $replacement);
        $this->row('observed', $crashedPid !== null && $replacement !== null
            ? 'the manager detected the dead worker and started a replacement'
            : 'no pid change was observed');

        $this->stopServe();
    }

    private function experimentCacheDown(): void
    {
        // The cache server is a child of whoever started it, and serve only
        // stops a cache it owns. For the experiment to kill the cache mid-run
        // it must own the cache server: start the cache first, let serve adopt
        // the already-running one, then kill the cache this experiment started
        // and watch the reads fall back to the database.
        $cache = (array) $this->config['cache'];
        $cacheHost = (string) $cache['host'];
        $cachePort = (int) $cache['port'];
        $snapshot = (string) $cache['data_dir'] . '/cache.snapshot';

        $cacheServer = OwnedProcess::startAndWaitForPort(
            [PHP_BINARY, dirname(__DIR__, 2) . '/bin/cache.php'],
            'cache',
            $this->logDir,
            $cacheHost,
            $cachePort,
            [
                'CACHE_HOST' => $cacheHost,
                'CACHE_PORT' => (string) $cachePort,
                'CACHE_SNAPSHOT' => $snapshot,
            ],
        );

        try {
            $this->startServe([], preserveCache: true);

            $this->heading('3. Cache down: every read bypasses to the database');

            $create = $this->http->request('POST', '/orders', (string) json_encode([
                'customer' => 'cache-down@example.com',
                'amount' => 700,
            ]));

            $id = $this->orderIdFrom($create);
            $this->row('seeded order', (string) $id);

            $hit = $this->http->request('GET', '/orders/' . $id);
            $this->row('GET while cache up', sprintf(
                'HTTP %d, X-Cache: %s',
                $hit['status'],
                $hit['headers']['x-cache'] ?? '?',
            ));

            $dbBefore = (float) ($this->metrics()['db.operations'] ?? 0.0);

            $cacheServer->stop();

            $after = $this->http->request('GET', '/orders/' . $id);
            $dbAfter = (float) ($this->metrics()['db.operations'] ?? 0.0);

            $this->row('GET after cache death', sprintf(
                'HTTP %d, X-Cache: %s',
                $after['status'],
                $after['headers']['x-cache'] ?? '?',
            ));
            $this->row('db.operations delta', sprintf('%d', (int) $dbAfter - (int) $dbBefore));
            $this->row('observed', ($after['headers']['x-cache'] ?? '') === 'miss' && $after['status'] === 200
                ? 'a dead cache is a miss, not a failure: reads fall through to the database'
                : sprintf('read after cache death answered HTTP %d', $after['status']));

            $this->stopServe();
        } finally {
            $cacheServer->stop();
        }
    }

    private function experimentSlowDatabase(): void
    {
        // The database delay only shows in a read that reaches the database,
        // so this serve runs without a cache tier (CACHE_ENABLED=0): every
        // read is a miss and pays the configured delay, and the metric
        // reports what the caller actually waited.
        $this->startServe([
            'DATABASE_LATENCY_MS' => '300',
            'CACHE_ENABLED' => '0',
        ]);

        $this->heading('4. A slow database: request latency tracks the database');

        $startedAt = microtime(true);
        $create = $this->http->request('POST', '/orders', (string) json_encode([
            'customer' => 'slow-db@example.com',
            'amount' => 500,
        ]));
        $createMs = round((microtime(true) - $startedAt) * 1000, 1);

        $id = $this->orderIdFrom($create);

        $startedAt = microtime(true);
        $read = $this->http->request('GET', '/orders/' . $id);
        $readMs = round((microtime(true) - $startedAt) * 1000, 1);

        $durationMs = round((float) ($this->metrics()['db.operation_duration'] ?? 0.0) * 1000, 1);

        $this->row('cache tier', 'off (CACHE_ENABLED=0)');
        $this->row('configured db delay', '300 ms');
        $this->row('POST /orders', sprintf('HTTP %d in %.1f ms', $create['status'], $createMs));
        $this->row('GET /orders/{id}', sprintf('HTTP %d in %.1f ms', $read['status'], $readMs));
        $this->row('db.operation_duration', sprintf('%.1f ms', $durationMs));
        $this->row('observed', $readMs >= 250 && $readMs < 1200
            ? 'every read paid the configured 300 ms database delay'
            : sprintf('reads took %.1f ms, expected roughly 300 ms', $readMs));

        $this->stopServe();
    }

    private function experimentQueueFull(): void
    {
        $this->startServe([
            'QUEUE_MAX_SIZE' => '5',
            'WORKER_POOL_MIN' => '1',
            'WORKER_POOL_MAX' => '1',
        ]);

        $this->heading('5. A full queue: the producer is told to retry, not blocked');

        // Fill the queue with slow jobs the single worker cannot keep up with,
        // then probe the producer at and under the limit.
        $this->publishSlowJobs(8, 1.0);

        $deadline = microtime(true) + self::RECOVERY_DEADLINE_SECONDS;

        while (microtime(true) < $deadline) {
            if ($this->queueDepth() >= 5) {
                break;
            }

            usleep((int) (self::PROBE_INTERVAL_SECONDS * 1_000_000));
        }

        $this->row('queue max size', '5');
        $probe = $this->http->request('POST', '/orders', (string) json_encode([
            'customer' => 'full-queue@example.com',
            'amount' => 900,
        ]));

        $fullBody = json_decode($probe['body'], true);
        $this->row('POST while full', sprintf(
            'HTTP %d, Retry-After: %s',
            $probe['status'],
            $probe['headers']['retry-after'] ?? '-',
        ));
        $this->row('queueDepth in response', (string) ($fullBody['queueDepth'] ?? '?'));

        // Once the single worker has drained below the limit the same producer
        // call is accepted again - backpressure is a signal, not a wall.
        $accepted = null;
        $deadline = microtime(true) + self::DRAIN_DEADLINE_SECONDS;

        while (microtime(true) < $deadline) {
            if ($this->queueDepth() < 5) {
                $accepted = $this->http->request('POST', '/orders', (string) json_encode([
                    'customer' => 'full-queue-after@example.com',
                    'amount' => 900,
                ]));
                break;
            }

            usleep((int) (self::PROBE_INTERVAL_SECONDS * 1_000_000));
        }

        $this->row('POST below capacity', $accepted === null
            ? 'did not drain in time'
            : sprintf('HTTP %d', $accepted['status']));
        $this->row('observed', $probe['status'] === 429 && $accepted !== null && $accepted['status'] === 201
            ? 'at capacity the producer gets a 429 and nothing is enqueued; below it, the same call is accepted'
            : sprintf('at capacity HTTP %d, below capacity HTTP %s', $probe['status'], $accepted['status'] ?? 'n/a'));

        $this->stopServe();
    }

    /**
     * @param array<string, string> $overrides the whole serve environment;
     *                                         a variable not named here is
     *                                         absent, not inherited
     */
    private function startServe(array $overrides, bool $preserveCache = false): void
    {
        $this->stopServe();

        if (!$preserveCache) {
            OwnedProcess::removeTree((string) $this->config['cache']['data_dir']);
        }

        $this->stopStaleDatabaseServer();
        OwnedProcess::removeTree((string) $this->config['database']['data_dir']);
        OwnedProcess::removeTree((string) $this->config['queue']['data_dir']);
        OwnedProcess::removeTree((string) $this->config['workers']['data_dir']);

        $env = $overrides + [
            'PLATFORM_ENV' => 'demo',
            'CACHE_ENABLED' => '1',
            'QUEUE_MAX_SIZE' => '500',
        ];

        $this->serve = OwnedProcess::startAndWaitForPort(
            [PHP_BINARY, dirname(__DIR__, 2) . '/bin/platform.php', 'serve'],
            'serve',
            $this->logDir,
            $this->host,
            $this->port,
            $env,
        );

        // serve starts the pool but nothing consumes the queue: the consumer
        // is a separate process (the demo runs them side by side). Publish a
        // job into an unconsumed queue and it just sits there, which would
        // break experiments 1 and 5 that need workers to drain it, so every
        // experiment owns a consumer alongside its serve. The consumer adopts
        // the already-running pool and its first loop writes the worker status
        // file - that file existing is the "consumer is up" signal, because a
        // process that has not finished one loop has not consumed anything.
        $this->consume = OwnedProcess::start(
            [PHP_BINARY, dirname(__DIR__, 2) . '/bin/platform.php', 'queue:consume'],
            'consume',
            $this->logDir,
            $env,
        );

        $statusFile = (string) $this->config['workers']['data_dir'] . '/workers.status.json';
        $ready = OwnedProcess::waitForQuietly(
            static fn (): bool => is_file($statusFile),
            10.0,
        );

        if (!$ready) {
            $this->stopServe();

            throw new RuntimeException(sprintf(
                'The queue consumer did not come up in time. Output: %s',
                $this->consume?->output() ?? '',
            ));
        }
    }

    private function stopServe(): void
    {
        $this->consume?->stop();
        $this->consume = null;
        $this->serve?->stop();
        $this->serve = null;
    }

    /** @param int $count negative/zero is a programming error, not an experiment */
    private function publishSlowJobs(int $count, float $seconds): void
    {
        $clock = new SystemClock();
        $producer = new Producer(
            new InMemoryQueue($clock, new FileStorage($this->queueLog)),
            new JobFactory($clock, new MetricsCollector()),
        );

        for ($i = 0; $i < $count; $i++) {
            $producer->dispatch('demo.slow', ['seconds' => $seconds], maxAttempts: 1);
        }
    }

    private function queueDepth(): int
    {
        $response = $this->http->request('GET', '/queue/status');
        $body = json_decode($response['body'], true);

        return (int) ($body['queue']['depth'] ?? 0);
    }

    /** @return list<int> pids of every worker the pool considers alive */
    private function workerPids(): array
    {
        $socket = (string) ($this->config['workers']['socket'] ?? '');

        if ($socket === '') {
            return [];
        }

        try {
            $stats = new WorkerPoolClient($socket, 3.0)->stats();
        } catch (ConnectionFailedException | ConnectionClosedException | ServerErrorException) {
            return [];
        }

        $pids = [];

        foreach ($stats as $worker) {
            if ((string) $worker['state'] !== 'DEAD') {
                $pids[] = (int) $worker['pid'];
            }
        }

        sort($pids);

        return $pids;
    }

    /** @return array<string, float> */
    private function metrics(): array
    {
        $response = $this->http->request('GET', '/metrics');

        if ($response['status'] !== 200) {
            throw new RuntimeException(sprintf('GET /metrics answered %d.', $response['status']));
        }

        $metrics = [];

        foreach (explode("\n", $response['body']) as $line) {
            if (preg_match('/^(\S+)\s+(\S+)$/', trim($line), $matches) !== 1) {
                continue;
            }

            $metrics[$matches[1]] = is_numeric($matches[2]) ? (float) $matches[2] : 0.0;
        }

        return $metrics;
    }

    /**
     * @param array{status: int, body: string, headers: array<string, string>} $response
     */
    private function orderIdFrom(array $response): string
    {
        if (str_starts_with((string) ($response['headers']['location'] ?? ''), '/orders/')) {
            return substr((string) $response['headers']['location'], strlen('/orders/'));
        }

        $body = json_decode($response['body'], true);

        if (is_array($body) && isset($body['id']) && is_string($body['id'])) {
            return $body['id'];
        }

        throw new RuntimeException(sprintf('Order create did not return an id (HTTP %d).', $response['status']));
    }

    private function refuseIfPlatformAlreadyRunning(): void
    {
        if (OwnedProcess::portAnswers($this->host, $this->port, 0.3)) {
            throw new RuntimeException(sprintf(
                'The platform is already answering on port %d. Stop the running serve before running the experiments.',
                $this->port,
            ));
        }
    }

    private function stopStaleDatabaseServer(): void
    {
        if (!OwnedProcess::portAnswers($this->host, (int) $this->config['database']['port'], 0.3)) {
            return;
        }

        $pidFile = (string) $this->config['database']['data_dir'] . '/minidb.pid';
        $pid = is_file($pidFile) ? (int) trim((string) file_get_contents($pidFile)) : 0;

        if ($pid > 0) {
            posix_kill($pid, SIGTERM);
            OwnedProcess::waitFor(
                static fn (): bool => !posix_kill($pid, 0),
                20.0,
                'the stale database server to stop',
            );
        }
    }

    /**
     * @param list<int> $history
     */
    private function compressHistory(array $history): string
    {
        $compressed = [];

        foreach ($history as $depth) {
            if (end($compressed) !== $depth) {
                $compressed[] = $depth;
            }
        }

        return implode(' -> ', array_map(strval(...), $compressed));
    }

    private function heading(string $text): void
    {
        printf("\n%s\n", $text);
    }

    private function row(string $label, string $value): void
    {
        printf("  %-24s %s\n", $label, $value);
    }
}
