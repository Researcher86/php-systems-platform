<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Benchmarks;

use Closure;
use PhpSystemsPlatform\Storage\Database;
use PhpSystemsPlatform\Storage\Migrator;
use PhpSystemsPlatform\Support\OwnedProcess;
use RuntimeException;

/**
 * PLAN Step 29's load tests as one command that owns a platform for its
 * duration and stops everything it started. One corpus of orders is behind
 * every read phase:
 *
 *   A  GET /health       what the server itself costs with no work behind it
 *   C1 GET /orders/{id}  every order read for the first time: every request a
 *                        cache miss, so every request reaches the database
 *   C2 GET /orders/{id}  the same orders again: every request a cache hit, so
 *                        the gap between C1 and C2 is the cache doing its job
 *   B  GET /orders/{id}  the same orders on a serve started with
 *                        CACHE_ENABLED=0 - which is what "without cache" means
 *                        here: no cache server, no cache lookup, no fallback
 *   D  background jobs   the platform's own queue benchmark, 1,000 jobs
 *   E  worker scaling    the same workload through 1, 2, 4 and 8 workers
 *
 * The corpus is written straight into the database rather than created over
 * HTTP, and that is not a shortcut: the create path populates the cache on
 * write, so an order created through the API is already cached and a "cache
 * miss" phase built from API-created orders would be measuring hits and
 * calling them misses. Direct inserts mean the read phases start against an
 * empty cache, and cost one write per order instead of an HTTP round trip.
 *
 * C1 and C2 run in that order on purpose: C2's hits are C1's misses having
 * done their job, so the hit phase is a consequence of the miss phase instead
 * of a second thing that had to be arranged.
 *
 * Test B restarts the platform, because the cache tier is decided when serve
 * starts and not per request. A serve that fails to start puts its own output
 * in the exception; a run that fails later keeps its child logs in the temp
 * log directory.
 */
final class LoadTestRunner
{
    public const float SERVE_STOP_DEADLINE_SECONDS = 20.0;

    public const int MAX_REQUESTS = 20000;

    private const float PORT_PROBE_TIMEOUT_SECONDS = 0.3;

    private ?OwnedProcess $serve = null;

    /**
     * @param Closure(int, int): (array<string, mixed>|null) $queueBenchmark
     *        the platform's own queue measurement, so Tests D and E run the
     *        code path the `benchmark` command runs rather than a second one
     * @param list<int>                              $workerCounts  Test E
     */
    public function __construct(
        private readonly Closure $queueBenchmark,
        private readonly int $requests = 1000,
        private readonly int $concurrency = 8,
        private readonly int $jobs = 1000,
        private readonly int $baselineWorkers = 4,
        private readonly array $workerCounts = [1, 2, 4, 8],
        private readonly ProcessCostReader $costs = new ProcessCostReader(),
    ) {
    }

    public function run(): LoadTestReport
    {
        $this->refuseWorkloadOutsideASaneRange();

        /** @var array<string, mixed> $config */
        $config = require dirname(__DIR__, 2) . '/config/platform.php';
        $http = (array) $config['http'];
        $database = (array) $config['database'];
        $host = (string) $http['host'];
        $port = (int) $http['port'];
        $baseUrl = sprintf('http://%s:%d', $host, $port);

        $this->refuseIfPlatformAlreadyRunning($host, $port);
        OwnedProcess::stopStaleDatabaseServer((string) $database['host'], (int) $database['port'], (string) $database['data_dir'], self::SERVE_STOP_DEADLINE_SECONDS);
        OwnedProcess::removeTree(dirname((string) $database['data_dir']));

        $logDir = sys_get_temp_dir() . '/php-systems-platform-load-' . uniqid('', true);
        OwnedProcess::mkdir($logDir);

        try {
            $this->startServe($logDir, 'serve', $host, $port, []);
            $corpus = $this->seedCorpus($database);
            $paths = array_map(static fn (string $id): string => '/orders/' . $id, $corpus);

            $phases = [
                $this->phase('A', 'GET /health (server only)', ['/health'], $this->requests, $baseUrl),
                $this->phase('C1', 'GET /orders/{id} (first read of each order)', $paths, count($paths), $baseUrl),
                $this->phase('C2', 'GET /orders/{id} (same orders, now cached)', $paths, count($paths), $baseUrl),
            ];

            $this->stopServe();

            $this->startServe($logDir, 'serve-nocache', $host, $port, ['CACHE_ENABLED' => '0']);
            $phases[] = $this->phase('B', 'GET /orders/{id} (CACHE_ENABLED=0)', $paths, count($paths), $baseUrl);
            $this->stopServe();

            $scaling = [];
            $queue = [];

            // D (the configured pool size) is also one of E's sizes. Each size
            // is measured once, so the report never shows two different
            // numbers for the same configuration; D is E's row for that size.
            $counts = array_values(array_unique([$this->baselineWorkers, ...$this->workerCounts]));
            sort($counts);

            foreach ($counts as $workers) {
                $metrics = ($this->queueBenchmark)($this->jobs, $workers);

                if (!is_array($metrics)) {
                    throw new RuntimeException(sprintf(
                        'The queue measurement for %d workers could not be taken.',
                        $workers,
                    ));
                }

                $scaling[] = $metrics;

                if ($workers === $this->baselineWorkers) {
                    $queue = $metrics;
                }
            }

            OwnedProcess::removeTree($logDir);

            return new LoadTestReport(
                $this->environment($config, $baseUrl, count($corpus)),
                $phases,
                $queue,
                $scaling,
            );
        } finally {
            $this->stopServe();
        }
    }

    /**
     * One measurement: $requests requests over $paths, with the serve's CPU
     * and peak memory read either side of them.
     *
     * The request count is passed rather than derived from the path list
     * because the two phases mean different things by it: the health phase
     * cycles one path, and each read phase is given exactly one request per
     * order, which is what makes "every request a miss" true rather than
     * approximately true.
     *
     * @param list<string> $paths
     *
     * @return array<string, mixed>
     */
    private function phase(string $test, string $name, array $paths, int $requests, string $baseUrl): array
    {
        $load = new HttpLoadTest($baseUrl, $this->concurrency);
        $pid = $this->serve?->pid() ?? 0;
        $before = $this->costs->read($pid);
        $result = $load->run($test, $paths, $requests);
        $after = $this->costs->read($pid);

        return ['test' => $test, 'name' => $name]
            + $result->toArray()
            + [
                'serve_cpu_seconds' => $after->cpuSince($before),
                'serve_peak_rss_mb' => round($after->peakRssBytes / (1024 * 1024), 1),
            ];
    }

    /**
     * Write the corpus the read phases address.
     *
     * The rows are inserted directly because they are read-only input to a
     * benchmark: the write path's job is to be measured elsewhere, and its
     * cache-populating side effect would make the miss phases lie.
     *
     * @param array<string, mixed> $database
     *
     * @return list<string> the seeded order ids
     */
    private function seedCorpus(array $database): array
    {
        $db = Database::connect($database);
        $ids = [];
        $now = gmdate('Y-m-d\TH:i:s\Z');

        try {
            // Serve has already migrated; repeating it is cheap and keeps
            // this independent of that ordering.
            Migrator::migrate($db);

            for ($i = 0; $i < $this->requests; $i++) {
                $id = sprintf('load-%06d', $i);
                $db->write(
                    'INSERT INTO orders (id, customer, amount, product, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [$id, 'Load Test Customer', '19.99', 'SKU-STANDARD', 'created', $now, $now],
                );
                $ids[] = $id;
            }
        } finally {
            $db->close();
        }

        return $ids;
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function environment(array $config, string $baseUrl, int $corpus): array
    {
        return [
            'base_url' => $baseUrl,
            'php' => PHP_VERSION,
            'os' => php_uname(),
            'cpu_cores' => (int) (trim((string) shell_exec('nproc 2>/dev/null')) ?: 0),
            'memory_limit' => (string) ini_get('memory_limit'),
            'concurrency' => $this->concurrency,
            'requests_per_phase' => $this->requests,
            'corpus_orders' => $corpus,
            'queue_jobs' => $this->jobs,
            'scaling_worker_counts' => implode(',', $this->workerCounts),
            'workers_configured' => (int) $config['workers']['count'],
            'queue_max_size' => (int) $config['queue']['max_size'],
            'cache_enabled_for_b' => 'no (CACHE_ENABLED=0)',
        ];
    }

    private function refuseWorkloadOutsideASaneRange(): void
    {
        if ($this->requests < 1 || $this->requests > self::MAX_REQUESTS) {
            throw new RuntimeException(sprintf(
                'requests must be between 1 and %d; a run writes one row per request.',
                self::MAX_REQUESTS,
            ));
        }

        if ($this->concurrency < 1 || $this->concurrency > 64) {
            throw new RuntimeException('concurrency must be between 1 and 64.');
        }

        if ($this->jobs < 1 || $this->jobs > 5000) {
            throw new RuntimeException('jobs must be between 1 and 5000.');
        }
    }

    private function refuseIfPlatformAlreadyRunning(string $host, int $port): void
    {
        if (OwnedProcess::portAnswers($host, $port, self::PORT_PROBE_TIMEOUT_SECONDS)) {
            throw new RuntimeException(sprintf(
                'The platform is already answering on port %d. Stop the running serve before loading it.',
                $port,
            ));
        }
    }

    /**
     * @param array<string, string> $env overrides on top of this process's
     *                                   environment
     */
    private function startServe(string $logDir, string $name, string $host, int $port, array $env): void
    {
        $this->serve = OwnedProcess::startAndWaitForPort(
            [PHP_BINARY, dirname(__DIR__, 2) . '/bin/platform.php', 'serve'],
            $name,
            $logDir,
            $host,
            $port,
            // OwnedProcess treats a non-empty env as the whole environment.
            $env === [] ? [] : array_merge(getenv(), $env),
        );
    }

    /** SIGTERM and wait: a serve left behind would hold the next run's port. */
    private function stopServe(): void
    {
        $this->serve?->stop();
        $this->serve = null;
    }
}
