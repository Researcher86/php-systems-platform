<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Experiments;

use Closure;
use PhpSystemsPlatform\Queue\Jobs\DemoSlowJob;
use PhpSystemsPlatform\Queue\JournalOnlyQueue;
use PhpSystemsPlatform\Support\HttpProbe;
use PhpSystemsPlatform\Support\OwnedProcess;
use PhpWorkerPool\Sdk\WorkerPoolClient;
use RuntimeException;

/**
 * PLAN Step 30's five failure/overload experiments, run against a platform
 * this command owns and stops:
 *
 *   1. slow workers  - a pool that is the bottleneck lets the queue grow
 *   2. worker crash  - the pool manager detects it and replaces the worker
 *   3. cache down    - every read bypasses to the database, still 200
 *   4. slow database - request latency tracks the configured database delay
 *   5. queue full    - the producer is told to retry, not left to block
 *
 * Each experiment restarts the platform with just the configuration it needs
 * (a fixed pool, a latency, a small queue), so each is reproducible on its
 * own. Everything observed is what the platform already reports - /metrics,
 * /queue/status, the pool's stats and the X-Cache header - rather than
 * experiment-only instrumentation.
 *
 * Like the load run, the command refuses to start on a port already being
 * served, wipes the platform's data directories, and stops everything it
 * started before returning, on every path.
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

    private readonly HttpProbe $http;

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
        try {
            $this->refuseIfPlatformAlreadyRunning();

            $this->logDir = sys_get_temp_dir() . '/php-systems-platform-experiments-' . uniqid('', true);
            OwnedProcess::mkdir($this->logDir);

            $this->experimentSlowWorkers();
            $this->experimentWorkerCrash();
            $this->experimentCacheDown();
            $this->experimentSlowDatabase();
            $this->experimentQueueFull();
        } catch (RuntimeException $e) {
            // A failed experiment is reported, not a PHP fatal (exit 255).
            fwrite(STDERR, sprintf('experiments: %s%s', $e->getMessage(), PHP_EOL));

            return 1;
        } finally {
            $this->stopServe();

            if ($this->logDir !== '') {
                OwnedProcess::removeTree($this->logDir);
            }
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

        // A zero only counts as "drained" after a few samples, so a reading
        // taken before the backlog shows up cannot end the watch early.
        $this->poll(function () use (&$history, &$peak): bool {
            $depth = $this->queueDepth();
            $history[] = $depth;
            $peak = max($peak, $depth);

            return $depth === 0 && count($history) > 2;
        }, self::DRAIN_DEADLINE_SECONDS);

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

        // The crash and the replacement can land in different polls, so each
        // pid is remembered from the first poll that shows it.
        $replacement = null;
        $crashedPid = null;

        $this->poll(function () use ($before, &$crashedPid, &$replacement): bool {
            $current = $this->workerPids();
            $crashedPid ??= array_values(array_diff($before, $current))[0] ?? null;
            $replacement ??= array_values(array_diff($current, $before))[0] ?? null;

            return $crashedPid !== null && $replacement !== null;
        }, self::RECOVERY_DEADLINE_SECONDS);

        $this->row('crashed pid', $crashedPid === null ? 'not observed' : (string) $crashedPid);
        $this->row('replacement pid', $replacement === null ? 'not observed' : (string) $replacement);
        $this->row('observed', $crashedPid !== null && $replacement !== null
            ? 'the manager detected the dead worker and started a replacement'
            : 'no pid change was observed');

        $this->stopServe();
    }

    private function experimentCacheDown(): void
    {
        // To kill the cache mid-run this command must own it: start it first,
        // let serve adopt the running one (serve only stops a cache it
        // started), then stop it and watch reads fall back to the database.
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

            $id = $this->orderIdFrom($this->postOrder('cache-down@example.com', 700));
            $this->row('seeded order', $id);

            $hit = $this->http->request('GET', '/orders/' . $id);
            $this->row('GET while cache up', sprintf(
                'HTTP %d, X-Cache: %s',
                $hit['status'],
                $hit['headers']['x-cache'] ?? '?',
            ));

            $dbBefore = (int) ($this->http->metrics()['db.operations'] ?? 0.0);

            $cacheServer->stop();

            $after = $this->http->request('GET', '/orders/' . $id);
            $dbAfter = (int) ($this->http->metrics()['db.operations'] ?? 0.0);

            $this->row('GET after cache death', sprintf(
                'HTTP %d, X-Cache: %s',
                $after['status'],
                $after['headers']['x-cache'] ?? '?',
            ));
            $this->row('db.operations delta', (string) ($dbAfter - $dbBefore));
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
        // Without a cache tier every read reaches the database and pays the
        // configured delay.
        $this->startServe([
            'DATABASE_LATENCY_MS' => '300',
            'CACHE_ENABLED' => '0',
        ]);

        $this->heading('4. A slow database: request latency tracks the database');

        $startedAt = microtime(true);
        $create = $this->postOrder('slow-db@example.com', 500);
        $createMs = round((microtime(true) - $startedAt) * 1000, 1);

        $id = $this->orderIdFrom($create);

        $startedAt = microtime(true);
        $read = $this->http->request('GET', '/orders/' . $id);
        $readMs = round((microtime(true) - $startedAt) * 1000, 1);

        $durationMs = round((float) ($this->http->metrics()['db.operation_duration'] ?? 0.0) * 1000, 1);

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

        $this->poll(fn (): bool => $this->queueDepth() >= 5, self::RECOVERY_DEADLINE_SECONDS);

        $this->row('queue max size', '5');
        $probe = $this->postOrder('full-queue@example.com', 900);

        $fullBody = json_decode($probe['body'], true);
        $this->row('POST while full', sprintf(
            'HTTP %d, Retry-After: %s',
            $probe['status'],
            $probe['headers']['retry-after'] ?? '-',
        ));
        $this->row('queueDepth in response', (string) ($fullBody['queueDepth'] ?? '?'));

        // Once the single worker has drained below the limit the same producer
        // call is accepted again - backpressure is a signal, not a wall.
        $accepted = $this->poll(fn (): bool => $this->queueDepth() < 5, self::DRAIN_DEADLINE_SECONDS)
            ? $this->postOrder('full-queue-after@example.com', 900)
            : null;

        $this->row('POST below capacity', $accepted === null
            ? 'did not drain in time'
            : sprintf('HTTP %d', $accepted['status']));
        $this->row('observed', $probe['status'] === 429 && $accepted !== null && $accepted['status'] === 201
            ? 'at capacity the producer gets a 429 and nothing is enqueued; below it, the same call is accepted'
            : sprintf('at capacity HTTP %d, below capacity HTTP %s', $probe['status'], $accepted['status'] ?? 'n/a'));

        $this->stopServe();
    }

    /**
     * @param array<string, string> $overrides the whole serve environment
     *                                         (plus the defaults below); a
     *                                         variable not named is absent,
     *                                         not inherited
     */
    private function startServe(array $overrides, bool $preserveCache = false): void
    {
        $this->stopServe();

        if (!$preserveCache) {
            OwnedProcess::removeTree((string) $this->config['cache']['data_dir']);
        }

        OwnedProcess::stopStaleDatabaseServer($this->host, (int) $this->config['database']['port'], (string) $this->config['database']['data_dir']);
        OwnedProcess::removeTree((string) $this->config['database']['data_dir']);
        OwnedProcess::removeTree((string) $this->config['queue']['data_dir']);
        OwnedProcess::removeTree((string) $this->config['workers']['data_dir']);

        $env = $overrides + [
            // Every data dir in config/platform.php is under
            // sys_get_temp_dir(), i.e. TMPDIR. Without it (macOS sets it to
            // /var/folders/...) the children would use /tmp while this
            // process clears and polls the TMPDIR paths.
            'TMPDIR' => sys_get_temp_dir(),
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

        // serve starts the pool but does not consume the queue, and
        // experiments 1 and 5 need the queue drained, so every serve gets a
        // consumer alongside it. The consumer's first loop writes the worker
        // status file, which makes that file the "consumer is up" signal.
        $consumer = OwnedProcess::start(
            [PHP_BINARY, dirname(__DIR__, 2) . '/bin/platform.php', 'queue:consume'],
            'consume',
            $this->logDir,
            $env,
        );
        $this->consume = $consumer;

        $statusFile = (string) $this->config['workers']['data_dir'] . '/workers.status.json';

        if (!OwnedProcess::waitForQuietly(static fn (): bool => is_file($statusFile), 10.0)) {
            // stopServe() drops the handle, so the local keeps the output
            // reachable for the message.
            $this->stopServe();

            throw new RuntimeException(sprintf(
                'The queue consumer did not come up in time. Output: %s',
                $consumer->output(),
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

    private function publishSlowJobs(int $count, float $seconds): void
    {
        $producer = JournalOnlyQueue::producer($this->queueLog);

        for ($i = 0; $i < $count; $i++) {
            $producer->dispatch(DemoSlowJob::TYPE, ['seconds' => $seconds], maxAttempts: 1);
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
        try {
            $stats = new WorkerPoolClient((string) $this->config['workers']['socket'], 3.0)->stats();
        } catch (RuntimeException) {
            // Any pool-client failure (refused, dropped, error, timed out):
            // a pool busy replacing a worker may not answer in time.
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

    /**
     * @return array{status: int, body: string, headers: array<string, string>}
     */
    private function postOrder(string $customer, int $amount): array
    {
        return $this->http->request('POST', '/orders', (string) json_encode(['customer' => $customer, 'amount' => $amount]));
    }

    /**
     * Probe every PROBE_INTERVAL_SECONDS until $probe holds or the deadline
     * passes.
     *
     * @param Closure(): bool $probe
     */
    private function poll(Closure $probe, float $deadlineSeconds): bool
    {
        return OwnedProcess::waitForQuietly($probe, $deadlineSeconds, self::PROBE_INTERVAL_SECONDS);
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
