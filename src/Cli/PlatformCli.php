<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Cli;

use PhpJobQueue\Dispatcher\JobDispatcher;
use PhpJobQueue\Job\Job;
use PhpJobQueue\Metrics\MetricsCollector;
use PhpJobQueue\Persistence\FileStorage;
use PhpJobQueue\Producer\JobFactory;
use PhpJobQueue\Producer\Producer;
use PhpJobQueue\Queue\InMemoryQueue;
use PhpJobQueue\Retry\FixedDelayRetry;
use PhpJobQueue\Support\SystemClock;
use PhpJobQueue\Worker\WorkerPool;
use PhpMiniCache\Sdk\CacheClient;
use PhpMiniCache\Sdk\CacheClientException;
use PhpMiniHttpServer\EventLoop\SelectLoop;
use PhpMiniHttpServer\Http\Protocol\HttpParser;
use PhpMiniHttpServer\Http\Protocol\ResponseEncoder;
use PhpMiniHttpServer\Metrics\ServerMetrics;
use PhpMiniHttpServer\Server\ConnectionHandler;
use PhpMiniHttpServer\Server\Server;
use PhpMiniHttpServer\Server\ServerConfig;
use PhpMiniHttpServer\Server\ServerStartException;
use PhpMiniHttpServer\Support\StderrLogger;
use PhpSystemsPlatform\Application\Application;
use PhpSystemsPlatform\Application\Handlers\FailWorkerHandler;
use PhpSystemsPlatform\Application\Handlers\HealthHandler;
use PhpSystemsPlatform\Application\Handlers\MetricsHandler;
use PhpSystemsPlatform\Application\Handlers\OrderCreateHandler;
use PhpSystemsPlatform\Application\Handlers\OrderReadHandler;
use PhpSystemsPlatform\Application\Handlers\OrderUpdateHandler;
use PhpSystemsPlatform\Application\Handlers\ParallelHandler;
use PhpSystemsPlatform\Application\Handlers\QueueStatusHandler;
use PhpSystemsPlatform\Application\Handlers\WorkersStatusHandler;
use PhpSystemsPlatform\Cache\CacheService;
use PhpSystemsPlatform\Domain\OrderService;
use PhpSystemsPlatform\Domain\SequentialOrderLoader;
use PhpSystemsPlatform\Http\Router;
use PhpSystemsPlatform\Memory\ForkedMemoryDemo;
use PhpSystemsPlatform\Memory\MemoryReporter;
use PhpSystemsPlatform\Memory\MemorySnapshot;
use PhpSystemsPlatform\Observability\MetricsRegistry;
use PhpSystemsPlatform\Observability\MetricsReporter;
use PhpSystemsPlatform\Observability\Trace;
use PhpSystemsPlatform\Queue\BackpressurePolicy;
use PhpSystemsPlatform\Queue\JobExecutor;
use PhpSystemsPlatform\Queue\JobRegistry;
use PhpSystemsPlatform\Queue\Jobs\FailingJob;
use PhpSystemsPlatform\Queue\Jobs\OrderProcessJob;
use PhpSystemsPlatform\Queue\QueueConsumer;
use PhpSystemsPlatform\Queue\QueueJournal;
use PhpSystemsPlatform\Storage\Database;
use PhpSystemsPlatform\Storage\Migrator;
use PhpSystemsPlatform\Storage\Repositories\CatalogRepository;
use PhpSystemsPlatform\Storage\Repositories\OrderRepository;
use PhpSystemsPlatform\Workers\ConcurrentOrderLoader;
use PhpSystemsPlatform\Workers\ConcurrentTaskRunner;
use PhpSystemsPlatform\Workers\ForkedOrderLoader;
use PhpSystemsPlatform\Workers\OrderLoadBenchmark;
use PhpSystemsPlatform\Workers\QueueBenchmark;
use PhpSystemsPlatform\Workers\WorkerFailureInjector;
use PhpSystemsPlatform\Workers\WorkerManager;
use PhpSystemsPlatform\Workers\WorkerMemoryBenchmark;
use PhpSystemsPlatform\Workers\WorkerRegistry;
use PhpWorkerPool\IPC\ConnectionClosedException;
use PhpWorkerPool\Protocol\Request as WorkerRequest;
use PhpWorkerPool\Sdk\ConnectionFailedException;
use PhpWorkerPool\Sdk\WorkerPoolClient;
use RuntimeException;

/**
 * The whole CLI surface of the platform, in one place.
 *
 * Deliberately small - no framework, no DI, no command classes for their own
 * sake. Each command is an entry in the table below; the dispatch match in
 * run() grows a real arm as the corresponding platform feature lands. See
 * docs/architecture.md for where each command sits in the process model.
 */
final class PlatformCli
{
    /**
     * @var array<string, string>
     */
    private const COMMANDS = [
        'serve' => 'Start the HTTP server and application.',
        'worker' => 'Start the worker pool and queue consumer.',
        'queue:publish' => 'Publish sample jobs into the queue: queue:publish <type> [payload-json] [key].',
        'queue:consume' => 'Run the queue consumer (pairs with worker pool).',
        'queue:status' => 'Show queue depth and job counters.',
        'queue:job' => 'Show one job\'s full metadata and attempt history: queue:job <id>.',
        'workers:status' => 'Show the queue consumer worker lifecycle.',
        'status' => 'Show the state of every platform component.',
        'demo' => 'Run the complete end-to-end platform story.',
        'benchmark' => 'Run the queue benchmark: benchmark <jobs> <workers>.',
        'orders:compare' => 'Compare sequential and concurrent order loading: orders:compare <rounds> <delay-ms>.',
        'memory:demo' => 'Demonstrate fork() and copy-on-write memory behavior.',
        'workers:memory' => 'Measure worker process memory (1, 2, 4, 8 workers).',
        'idempotency:demo' => 'Demonstrate at-least-once delivery and the idempotency guard.',
        'failure:demo' => 'Reproduce the failure scenarios end to end.',
        'metrics' => 'Print the platform\'s standard metric snapshot.',
        'trace' => 'Print the spans recorded for one request_id: trace <request_id>.',
    ];

    /**
     * The cache server this serve spawned, when it spawned one. The database
     * server is tracked by pid file; the cache has no daemon mode, so its
     * process is owned directly and must not outlive the HTTP process. Same
     * for the worker pool Master (bin/worker.php): no daemon mode, so serve owns
     * it as a child and stops it on the way out.
     *
     * @var resource|null
     */
    private mixed $cacheProcess = null;

    /** @var resource|null */
    private mixed $workerProcess = null;

    /**
     * @param list<string> $argv
     */
    public function run(array $argv): int
    {
        $command = $argv[1] ?? 'help';

        if ($command === 'help' || $command === '--help' || $command === '-h') {
            return $this->printHelp();
        }

        if (!isset(self::COMMANDS[$command])) {
            fwrite(STDERR, sprintf("Unknown command: %s\n", $command));
            fwrite(STDERR, "Run 'php bin/platform.php help' for the command list.\n");

            return 1;
        }

        return $this->dispatch($command, $argv);
    }

    private function printHelp(): int
    {
        $width = max(array_map('strlen', array_keys(self::COMMANDS)));

        fwrite(STDOUT, "PHP Systems Platform\n\n");
        fwrite(STDOUT, "Commands\n\n");

        foreach (self::COMMANDS as $name => $description) {
            fwrite(STDOUT, sprintf("  %-{$width}s  %s\n", $name, $description));
        }

        fwrite(STDOUT, "\nRun a command with\n");
        fwrite(STDOUT, "  php bin/platform.php <command>\n");

        return 0;
    }

    /**
     * @param list<string> $argv
     */
    private function dispatch(string $command, array $argv): int
    {
        return match ($command) {
            'serve' => $this->serve(),
            'worker' => $this->worker(),
            'queue:publish' => $this->queuePublish(array_slice($argv, 2)),
            'queue:consume' => $this->queueConsume(),
            'queue:status' => $this->queueStatus(),
            'queue:job' => $this->queueJob(array_slice($argv, 2)),
            'workers:status' => $this->workersStatus(),
            'benchmark' => $this->queueBenchmark(array_slice($argv, 2)),
            'orders:compare' => $this->ordersCompare(array_slice($argv, 2)),
            'memory:demo' => $this->memoryDemo(),
            'workers:memory' => $this->workersMemory(array_slice($argv, 2)),
            'idempotency:demo' => $this->idempotencyDemo(),
            'failure:demo' => $this->failureDemo(),
            'metrics' => $this->metricsCommand(),
            'trace' => $this->traceCommand(array_slice($argv, 2)),
            'status' => $this->statusCommand(),

            // Real handlers land with their implementation phase.
            default => $this->notImplemented($command),
        };
    }

    /**
     * The HTTP server wired to the platform Application, one connection at a
     * time on the component's select loop. Routes are registered in
     * application(); every later phase that adds a feature registers it there
     * too, keeping this method about serving, not about routing.
     */
    private function serve(): int
    {
        $config = $this->config();
        $databaseConfig = $config['database'];

        // PLAN Step 23: the one shared registry every component reports into.
        // Wired before any client so the database and cache can be handed it
        // at construction time; serve()'s own report picks it up afterwards.
        $systemMetrics = new MetricsRegistry();

        // PLAN Step 24: the serve side's tracer over the same journal the
        // pool workers write into, so one request's whole chain - this
        // process's request/database spans and every worker's job.execute
        // spans - is readable from a single file.
        $systemTrace = new Trace($this->traceStorePath($config));

        $database = Database::connect($databaseConfig, 10, $systemMetrics, $systemTrace);

        $ownsDatabaseServer = false;

        try {
            $ownsDatabaseServer = $this->ensureDatabaseServer($databaseConfig);
            Migrator::migrate($database);
        } catch (RuntimeException $e) {
            fwrite(STDERR, $e->getMessage() . PHP_EOL);
            $database->close();

            return 1;
        }

        $cacheConfig = $config['cache'];

        $cache = CacheService::fromConfig($cacheConfig, $systemMetrics);

        $ownsCacheServer = false;

        try {
            $ownsCacheServer = $this->ensureCacheServer($cacheConfig);
        } catch (RuntimeException $e) {
            fwrite(STDERR, $e->getMessage() . PHP_EOL);
            $database->close();
            $this->stopDatabaseServerIfOwned($ownsDatabaseServer, $databaseConfig);

            return 1;
        }

        $workersConfig = $config['workers'];

        $ownsWorkerPool = false;
        $runner = null;

        try {
            $ownsWorkerPool = $this->ensureWorkerPoolServer($workersConfig);
            $runner = new ConcurrentTaskRunner(new WorkerPoolClient(
                $workersConfig['socket'],
                (float) $workersConfig['task_timeout'],
            ));
        } catch (RuntimeException $e) {
            fwrite(STDERR, $e->getMessage() . PHP_EOL);
            $database->close();
            $cache->close();
            $this->stopCacheServerIfOwned($ownsCacheServer);
            $this->stopWorkerPoolIfOwned($ownsWorkerPool);
            $this->stopDatabaseServerIfOwned($ownsDatabaseServer, $databaseConfig);

            return 1;
        }

        // PLAN Step 22: the /debug/fail-worker route exists only when the
        // platform is in a development/demo environment. Otherwise the
        // injector stays null and application() registers no such route.
        $failureInjector = $config['failure_injection']['enabled']
            ? new WorkerFailureInjector(new WorkerPoolClient(
                $workersConfig['socket'],
                (float) $workersConfig['task_timeout'],
            ))
            : null;

        $http = $config['http'];

        $producer = null;

        try {
            $producer = $this->producer($config['queue']);
        } catch (RuntimeException $e) {
            fwrite(STDERR, $e->getMessage() . PHP_EOL);
            $database->close();
            $cache->close();
            $this->stopCacheServerIfOwned($ownsCacheServer);
            $this->stopDatabaseServerIfOwned($ownsDatabaseServer, $databaseConfig);

            return 1;
        }

        $serverConfig = new ServerConfig(
            host: $http['host'],
            port: $http['port'],
            // PLAN Step 18's HTTP request timeout, in the config's own two
            // parts - a connection idle this long is reclaimed, one stuck
            // mid-header-block is reclaimed sooner (the Slowloris guard).
            // Both are only names for the component's own numbers until the
            // periodic sweep below actually calls the methods that enforce
            // them.
            connectionTimeout: (float) $http['request_timeout'],
            headerTimeout: (float) $http['header_timeout'],
        );
        $server = new Server($serverConfig);

        try {
            $server->start();
        } catch (ServerStartException $e) {
            fwrite(STDERR, $e->getMessage() . PHP_EOL);
            $database->close();
            $cache->close();
            $this->stopCacheServerIfOwned($ownsCacheServer);
            $this->stopDatabaseServerIfOwned($ownsDatabaseServer, $databaseConfig);

            return 1;
        }

        $parser = new HttpParser($serverConfig->maxHeaderBytes, $serverConfig->maxBodyBytes);
        $encoder = new ResponseEncoder();
        $logger = new StderrLogger();
        $metrics = new ServerMetrics();
        $loop = new SelectLoop();

        // PLAN Step 23: the platform's own report sits next to the
        // component's ServerMetrics and reads the shared registry plus the
        // live sources (queue journal, worker pool, this process's memory).
        // It backs both the /metrics route and the `metrics` CLI command.
        $metricsReporter = new MetricsReporter(
            $systemMetrics,
            new QueueJournal($config['queue']['data_dir'] . '/queue.log'),
            new WorkerPoolClient(
                $workersConfig['socket'],
                (float) $workersConfig['task_timeout'],
            ),
            new MemoryReporter(),
        );

        $application = $this->application($database, $cache, $producer, $runner, $config['queue']['data_dir'] . '/queue.log', $config['workers']['data_dir'] . '/workers.status.json', (int) $config['queue']['max_size'], $failureInjector, $metricsReporter, $systemTrace);

        $loop->onReadable($server->socket(), static function () use ($loop, $server, $parser, $encoder, $application, $metrics, $logger): void {
            $connection = $server->accept();

            if ($connection === null) {
                return;
            }

            $logger->log(sprintf('#%d connected from %s', $connection->id, $connection->remoteAddress()));

            new ConnectionHandler(
                loop: $loop,
                server: $server,
                connection: $connection,
                parser: $parser,
                application: $application,
                encoder: $encoder,
                metrics: $metrics,
                logger: $logger,
            )->start();
        });

        // The HTTP request timeout, enforced: every second, close whatever
        // has gone idle past connectionTimeout or spent too long mid-header
        // past headerTimeout. Without this sweep the two numbers above are
        // just config - Server measures both but nothing ever asks it to
        // act on them.
        $loop->every(1.0, static function () use ($server, $serverConfig, $logger): void {
            foreach ($server->closeIdleConnections($serverConfig->connectionTimeout) as $connection) {
                $logger->log(sprintf('#%d closed: idle past %.1fs', $connection->id, $serverConfig->connectionTimeout));
            }

            foreach ($server->closeSlowHeaderReads($serverConfig->headerTimeout) as $connection) {
                $logger->log(sprintf('#%d closed: header past %.1fs', $connection->id, $serverConfig->headerTimeout));
            }
        });

        pcntl_async_signals(true);

        $stop = static function () use ($loop, $server): void {
            $server->stop();
            $loop->stop();
        };

        pcntl_signal(SIGINT, $stop);
        pcntl_signal(SIGTERM, $stop);

        printf("Listening on tcp://%s:%d\n", $server->getHost(), $server->getPort());

        $loop->run();

        $server->stop();
        $database->close();
        $cache->close();
        $this->stopWorkerPoolIfOwned($ownsWorkerPool);
        $this->stopCacheServerIfOwned($ownsCacheServer);
        $this->stopDatabaseServerIfOwned($ownsDatabaseServer, $databaseConfig);
        printf("Shutdown complete.\n");

        return 0;
    }

    /**
     * The platform's routes, in their Application. Serves as the wiring note
     * for the platform as well: the health endpoint landed in Phase 3, the
     * order endpoints in Phase 4 on top of the injected Database, the
     * cache-first read path in Phase 5 on top of the injected CacheService,
     * and the write → enqueue → respond seam in the queue phase on top of the
     * injected Producer (null until the queue is wired, in which case writes
     * stay plain synchronous persists). The worker example in the same wire:
     * a CPU task split into chunks and run side by side on the pool in
     * parallel, exposed as GET /parallel (again null-tolerant - without a
     * pool the handler answers "not configured" instead of crashing serve).
     * The queue phase adds GET /queue/status, the journal-derived counters
     * the queue:status CLI prints, on top of the queue's data dir; the
     * worker-lifecycle phase adds GET /workers, the queue consumer's
     * forwarder snapshot. The backpressure phase (Step 17) adds a policy in
     * front of POST /orders itself - null-tolerant the same way, so a caller
     * with no queue log or no configured limit gets the old unbounded write
     * path back. Step 22 adds POST /debug/fail-worker, but only when a
     * failure injector exists to back it - i.e. only in development/demo
     * environments, per the step's own "only enabled in development/demo
     * mode" rule; a production serve has no such route at all.
     */
    private function application(Database $database, CacheService $cache, ?Producer $producer = null, ?ConcurrentTaskRunner $runner = null, string $queueLogPath = '', string $workersStatusPath = '', ?int $maxQueueSize = null, ?WorkerFailureInjector $failureInjector = null, ?MetricsReporter $metricsReporter = null, ?Trace $trace = null): Application
    {
        $orders = new OrderService(new OrderRepository($database), $producer);

        // PLAN Step 17: only a real queue has a depth to be overloaded, so
        // the policy exists exactly when the producer and the journal it
        // writes to both do.
        $backpressure = ($queueLogPath !== '' && $maxQueueSize !== null)
            ? new BackpressurePolicy(new QueueJournal($queueLogPath), $maxQueueSize)
            : null;

        $router = new Router();
        $router->get('/health', (new HealthHandler())(...));
        $router->post('/orders', (new OrderCreateHandler($orders, $cache, $backpressure, $trace))(...));
        $router->get('/orders/{id}', (new OrderReadHandler($orders, $cache))(...));
        $router->put('/orders/{id}', (new OrderUpdateHandler($orders, $cache))(...));
        $router->get('/parallel', (new ParallelHandler($runner))(...));
        $router->get('/queue/status', (new QueueStatusHandler($queueLogPath))(...));
        $router->get('/workers', (new WorkersStatusHandler($workersStatusPath))(...));

        // PLAN Step 23: observability is serve()'s wiring decision - the
        // route exists exactly when serve handed application() a reporter,
        // and serve always does.
        if ($metricsReporter !== null) {
            $router->get('/metrics', (new MetricsHandler($metricsReporter))(...));
        }

        // PLAN Step 22: the failure injection endpoint is not null-tolerant
        // - its absence on purpose is the point. A serve without injection
        // simply never registers the route.
        if ($failureInjector !== null) {
            $router->post('/debug/fail-worker', (new FailWorkerHandler($failureInjector))(...));
        }

        // PLAN Step 24: the Application boundary opens and closes one request
        // scope per HTTP answer, echoes X-Request-ID and records the
        // http.request span - all off, the moment no tracer is wired.
        return new Application($router, $metricsReporter?->registry(), $trace);
    }

    /**
     * Build the platform's producer: an in-memory queue that journals every
     * push into the queue's append-only log, fronted by the component's
     * Producer. Within one process the queue lives in memory; across
     * processes the log is the source of truth - queue:consume restores it
     * with InMemoryQueue::restoreFromStorage() and replays READY jobs, which
     * is where the at-least-once replay that this producer's last-attempt
     * semantics rely on happens.
     *
     * @param array<string, mixed> $config
     */
    private function producer(array $config): Producer
    {
        $dataDir = $config['data_dir'];

        if (!is_dir($dataDir) && !@mkdir($dataDir, 0o777, true) && !is_dir($dataDir)) {
            throw new RuntimeException(sprintf('Could not create queue data directory "%s".', $dataDir));
        }

        $clock = new SystemClock();

        return new Producer(
            new InMemoryQueue($clock, new FileStorage($dataDir . '/queue.log')),
            new JobFactory($clock, new MetricsCollector()),
        );
    }

    /**
     * Make sure a mini database server answers on the configured host/port
     * for the duration of this serve, and return whether this process is the
     * one that started it (and therefore the one that must stop it). A
     * server that was already running is reused and stays up afterwards.
     *
     * The platform owns the decision to run the database as its own process
     * - the component's CLI keeps that process, lifecycle and data separate
     * from the HTTP process, which is exactly the process boundary the lab
     * wants to be able to point at.
     *
     * @param array<string, mixed> $config
     */
    private function ensureDatabaseServer(array $config): bool
    {
        $script = $this->databaseServerBinary();
        $pidFile = $config['data_dir'] . '/minidb.pid';
        $logFile = $config['data_dir'] . '/minidb.log';

        $output = '';
        $hadServer = $this->runServerCli([$script, 'status', '--pid-file', $pidFile], $output) === 0;

        // The pid file is the authoritative "who owns it" answer, but a
        // server can be up without a reliable pid file (a stale one from a
        // crashed process, or another platform process that started it).
        // The port is the ground truth for "a server is running": only when
        // neither signal says so may we start one and own it. Otherwise two
        // processes each think they own the same server, and the second one
        // stops the first's infrastructure on the way out.
        if ($hadServer || $this->waitForPort($config['host'], (int) $config['port'], 0.3)) {
            printf("Database server already running on tcp://%s:%d\n", $config['host'], $config['port']);

            return false;
        }

        if (!is_dir($config['data_dir']) && !@mkdir($config['data_dir'], 0o777, true) && !is_dir($config['data_dir'])) {
            throw new RuntimeException(sprintf('Could not create database data directory "%s".', $config['data_dir']));
        }

        $code = $this->runServerCli([
            $script,
            'start',
            '--host', $config['host'],
            '--port', (string) $config['port'],
            '--data', $config['data_dir'],
            '--daemon',
            '--pid-file', $pidFile,
            '--log-file', $logFile,
        ], $output);

        if ($code !== 0) {
            throw new RuntimeException('Could not start the database server: ' . rtrim($output));
        }

        if (!$this->waitForPort($config['host'], (int) $config['port'])) {
            throw new RuntimeException(sprintf(
                'Database server did not start listening on tcp://%s:%d in time.',
                $config['host'],
                $config['port'],
            ));
        }

        printf("Database server listening on tcp://%s:%d\n", $config['host'], $config['port']);

        return true;
    }

    /** @param array<string, mixed> $config */
    private function stopDatabaseServerIfOwned(bool $owns, array $config): void
    {
        if (!$owns) {
            return;
        }

        $pidFile = $config['data_dir'] . '/minidb.pid';
        $output = '';

        if ($this->runServerCli([$this->databaseServerBinary(), 'stop', '--pid-file', $pidFile], $output) === 0) {
            printf("Database server stopped\n");

            return;
        }

        fwrite(STDERR, 'Database server could not be stopped: ' . rtrim($output) . PHP_EOL);
    }

    /**
     * @param list<string> $command
     */
    private function runServerCli(array $command, string &$output): int
    {
        $process = proc_open([PHP_BINARY, ...$command], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        if (!is_resource($process)) {
            throw new RuntimeException(sprintf('Could not run "%s".', implode(' ', $command)));
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        $output = trim($stdout . "\n" . $stderr);

        return $code;
    }

    private function databaseServerBinary(): string
    {
        return dirname(__DIR__, 2) . '/bin/minidb.php';
    }

    private function cacheServerBinary(): string
    {
        return dirname(__DIR__, 2) . '/bin/cache.php';
    }

    /**
     * Make sure a mini cache server answers on the configured host/port for
     * the duration of this serve, and return whether this process is the one
     * that started it (and therefore the one that must stop it). A server
     * that was already running is reused and stays up afterwards.
     *
     * The cache component has no daemon mode - its bin is a foreground
     * server - so unlike the database this process owns the cache as a
     * child: it spawns it with stdout/stderr redirected into the data
     * directory, keeps the process handle in $cacheProcess, and terminates
     * it on shutdown. The host/port/snapshot reach the child as environment
     * variables, the same contract the component's own bin/server.php uses.
     *
     * @param array<string, mixed> $config
     */
    private function ensureCacheServer(array $config): bool
    {
        if ($this->cacheServerAnswers($config)) {
            printf("Cache server already running on tcp://%s:%d\n", $config['host'], $config['port']);

            return false;
        }

        $dataDir = $config['data_dir'];

        if (!is_dir($dataDir) && !@mkdir($dataDir, 0o777, true) && !is_dir($dataDir)) {
            throw new RuntimeException(sprintf('Could not create cache data directory "%s".', $dataDir));
        }

        $process = proc_open(
            [PHP_BINARY, $this->cacheServerBinary()],
            [
                1 => ['file', $dataDir . '/cache.out', 'a'],
                2 => ['file', $dataDir . '/cache.err', 'a'],
            ],
            $pipes,
            null,
            [
                'CACHE_HOST' => $config['host'],
                'CACHE_PORT' => (string) $config['port'],
                'CACHE_SNAPSHOT' => $dataDir . '/cache.snapshot',
            ],
        );

        if (!is_resource($process)) {
            throw new RuntimeException('Could not start the cache server process.');
        }

        $this->cacheProcess = $process;

        if (!$this->waitForPort($config['host'], (int) $config['port'])) {
            $this->stopCacheServerIfOwned(true);

            throw new RuntimeException(sprintf(
                'Cache server did not start listening on tcp://%s:%d in time.',
                $config['host'],
                $config['port'],
            ));
        }

        printf("Cache server listening on tcp://%s:%d\n", $config['host'], $config['port']);

        return true;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function cacheServerAnswers(array $config): bool
    {
        $client = new CacheClient(host: $config['host'], port: $config['port'], timeoutSeconds: 0.5);

        try {
            $client->ping();
            $client->close();

            return true;
        } catch (CacheClientException) {
            return false;
        }
    }

    /**
     * Stop the cache server this serve started, if it started one: SIGTERM
     * is the cache's graceful shutdown (final snapshot included), so the
     * child is left to drain and exit before the handle is released.
     */
    private function stopCacheServerIfOwned(bool $owns): void
    {
        if (!$owns || !is_resource($this->cacheProcess)) {
            return;
        }

        $process = $this->cacheProcess;
        $this->cacheProcess = null;

        proc_terminate($process);
        proc_close($process);
        printf("Cache server stopped\n");
    }

    private function waitForPort(string $host, int $port, float $timeoutSeconds = 10.0): bool
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            $socket = @stream_socket_client(
                sprintf('tcp://%s:%d', $host, $port),
                $errorCode,
                $errorMessage,
                0.2,
            );

            if ($socket !== false) {
                fclose($socket);

                return true;
            }

            usleep(100_000);
        }

        return false;
    }

    /**
     * Make sure a worker pool answers on the configured socket for the
     * duration of this serve, and return whether this process is the one
     * that started it (and therefore the one that must stop it). A pool
     * already running is reused and stays up afterwards - the probe is one
     * ping over the socket, the same channel the HTTP control plane uses.
     *
     * Like the cache, the pool has no daemon mode: the platform spawns the
     * component's Master (bin/worker.php) as its own process and owns it
     * until shutdown, rather than swallowing the pool into the HTTP process
     * and losing the process boundary the lab wants to point at.
     *
     * @param array<string, mixed> $config
     */
    private function ensureWorkerPoolServer(array $config): bool
    {
        if ($this->workerPoolAnswers($config)) {
            printf("Worker pool already running on %s\n", $config['socket']);

            return false;
        }

        $dataDir = $config['data_dir'];

        if (!is_dir($dataDir) && !@mkdir($dataDir, 0o777, true) && !is_dir($dataDir)) {
            throw new RuntimeException(sprintf('Could not create worker data directory "%s".', $dataDir));
        }

        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/bin/worker.php'],
            [
                1 => ['file', $dataDir . '/worker.out', 'a'],
                2 => ['file', $dataDir . '/worker.err', 'a'],
            ],
            $pipes,
        );

        if (!is_resource($process)) {
            throw new RuntimeException('Could not start the worker pool process.');
        }

        $this->workerProcess = $process;

        if (!$this->waitForSocket($config['socket'])) {
            $this->stopWorkerPoolIfOwned(true);

            throw new RuntimeException(sprintf('Worker pool did not start listening on "%s" in time.', $config['socket']));
        }

        printf("Worker pool listening on %s\n", $config['socket']);

        return true;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function workerPoolAnswers(array $config): bool
    {
        $client = new WorkerPoolClient($config['socket'], (float) $config['task_timeout']);

        try {
            $client->call(new WorkerRequest('ping'));
            $client->close();

            return true;
        } catch (ConnectionFailedException | ConnectionClosedException) {
            return false;
        }
    }

    /**
     * Stop the worker pool Master this serve started, if it started one:
     * SIGTERM triggers the component's graceful shutdown (drain in-flight
     * tasks, exit the workers), then the handle is released.
     */
    private function stopWorkerPoolIfOwned(bool $owns): void
    {
        if (!$owns || !is_resource($this->workerProcess)) {
            return;
        }

        $process = $this->workerProcess;
        $this->workerProcess = null;

        proc_terminate($process);
        proc_close($process);
        printf("Worker pool stopped\n");
    }

    private function waitForSocket(string $socketPath, float $timeoutSeconds = 10.0): bool
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            $socket = @stream_socket_client(
                sprintf('unix://%s', $socketPath),
                $errorCode,
                $errorMessage,
                0.2,
            );

            if ($socket !== false) {
                fclose($socket);

                return true;
            }

            usleep(100_000);
        }

        return false;

    }

    /**
     * Publish one job into the journal-backed queue and exit. The producer
     * here is the same wiring serve() uses: an InMemoryQueue that appends
     * every push to the queue's log, so a job published this way is consumed
     * by the very same queue:consume that would handle a job enqueued over
     * HTTP. The optional third argument is the idempotency key (PLAN Step 20)
     * - hand `order.process <payload> order.process:<id>` and a redelivered
     * copy is deduplicated by the guard instead of settling the order again.
     *
     * @param list<string> $args
     */
    private function queuePublish(array $args): int
    {
        $type = $args[0] ?? null;

        if ($type === null) {
            fwrite(STDERR, "Usage: php bin/platform.php queue:publish <type> [payload-json] [key]\n");

            return 1;
        }

        $payload = [];

        if (isset($args[1])) {
            $decoded = json_decode($args[1], true);

            if (!is_array($decoded)) {
                fwrite(STDERR, "Payload must be a JSON object.\n");

                return 1;
            }

            $payload = $decoded;
        }

        $key = $args[2] ?? null;

        $config = $this->config();

        try {
            $producer = $this->producer($config['queue']);
        } catch (RuntimeException $e) {
            fwrite(STDERR, $e->getMessage() . PHP_EOL);

            return 1;
        }

        $job = $producer->dispatch(
            $type,
            $payload,
            maxAttempts: (int) $config['queue']['max_attempts'],
            idempotencyKey: $key,
        );

        printf(
            "Published job %s (type=%s, %d max attempts%s) to %s\n",
            $job->getId(),
            $type,
            $job->getMaxAttempts(),
            $key !== null ? sprintf(', key=%s', $key) : '',
            $config['queue']['data_dir'] . '/queue.log',
        );

        return 0;
    }

    /**
     * The long-running queue consumer: the second side of the queue phase.
     *
     * It restores the append-only journal into a queue (InMemoryQueue::
     * restoreFromStorage()), and dispatches every READY job it finds to the
     * php-worker-pool through Workers\WorkerManager - the Worker Manager of
     * PLAN Step 10. The pool's forked workers are what actually run a job
     * (its WorkerJobs handler), so worker lifecycle, dispatch, failure and
     * shutdown all belong to the pool, not to this process. The QueueConsumer
     * loop keeps the component's dispatch/answer/requeue machinery and
     * re-reads the journal, so a job published by another process while the
     * consumer lives is picked up on the next pass. SIGTERM/SIGINT stop it
     * gracefully.
     *
     * Jobs run on real worker processes that need the database and cache, and
     * the pool Master that owns them, so - like serve() - the consumer makes
     * sure those servers answer before it starts and stops them again if it
     * was the one that started them.
     *
     * The shutdown tail (PLAN Step 21) is observable and verified: run() has
     * stopped accepting and pulling, the dispatcher then finished executing,
     * drained and stopped the workers, and this method closes the resources,
     * checks the append-only journal for "not silently lost" and turns that
     * invariant into the exit code.
     */
    private function worker(): int
    {
        return $this->queueConsume();
    }

    private function queueConsume(): int
    {
        $config = $this->config();
        $queueConfig = $config['queue'];
        $databaseConfig = $config['database'];
        $cacheConfig = $config['cache'];
        $workersConfig = $config['workers'];

        $dataDir = $queueConfig['data_dir'];

        if (!is_dir($dataDir) && !@mkdir($dataDir, 0o777, true) && !is_dir($dataDir)) {
            fwrite(STDERR, sprintf('Could not create queue data directory "%s".', $dataDir) . PHP_EOL);

            return 1;
        }

        $clock = new SystemClock();
        $logPath = $dataDir . '/queue.log';

        $storage = new FileStorage($logPath);

        // Seed the consumer with every job the journal already knew about,
        // then restore them into the queue; the consumer's loop then picks
        // up only rows that arrive after this moment.
        $knownIds = [];
        foreach ($storage->load() as $id => $data) {
            $knownIds[$id] = true;
        }

        $queue = InMemoryQueue::restoreFromStorage($storage, $clock);

        $restored = new QueueJournal($logPath)->snapshot();
        printf("Consumer restoring queue from %s\n", $logPath);
        printf("  %d published, %d ready/delayed/processing\n", $restored['published'], $restored['depth']);

        $database = Database::connect($databaseConfig);

        $ownsDatabaseServer = false;
        $ownsCacheServer = false;
        $ownsWorkerPool = false;

        try {
            $ownsDatabaseServer = $this->ensureDatabaseServer($databaseConfig);
            Migrator::migrate($database);
            $ownsCacheServer = $this->ensureCacheServer($cacheConfig);
            $ownsWorkerPool = $this->ensureWorkerPoolServer($workersConfig);
        } catch (RuntimeException $e) {
            fwrite(STDERR, $e->getMessage() . PHP_EOL);
            $database->close();
            $this->stopWorkerPoolIfOwned($ownsWorkerPool);
            $this->stopCacheServerIfOwned($ownsCacheServer);
            $this->stopDatabaseServerIfOwned($ownsDatabaseServer, $databaseConfig);

            return 1;
        }

        // Each php-job-queue worker is now a forwarder: it takes a job from
        // the dispatcher, hands it to the pool as a job.execute task through
        // WorkerManager, and waits for the pool's verdict. The WorkerPoolClient
        // is built lazily inside the handler so every forked forwarder gets
        // its own connection to the Master instead of sharing the parent's.
        $metrics = new MetricsCollector();
        $workerManager = null;
        $pool = new WorkerPool(
            size: (int) $queueConfig['consumers'],
            handler: static function (Job $job) use (&$workerManager, $workersConfig): mixed {
                $workerManager ??= new WorkerManager(new WorkerPoolClient(
                    (string) $workersConfig['socket'],
                    (float) $workersConfig['task_timeout'],
                ));

                $workerManager->execute($job);

                return null;
            },
            metrics: $metrics,
        );
        $dispatcher = new JobDispatcher(
            queue: $queue,
            workerPool: $pool,
            retryPolicy: new FixedDelayRetry((int) $queueConfig['retry_delay']),
            clock: $clock,
            // A job can legitimately be in flight at the pool for up to the
            // pool's task timeout; visibility must span that whole round-trip
            // or a slow job is requeued while a worker is still finishing it.
            visibilityTimeout: (int) $workersConfig['task_timeout'],
            storage: new FileStorage($logPath),
            metrics: $metrics,
            // PLAN Step 19's "do not retry every possible error": a payload
            // JobRegistry::validate() already knows can never succeed is
            // never retried, no matter how many attempts remain - the same
            // verdict a pre-dispatch check would reach, now made at the
            // point the component itself exposes for it.
            shouldRetry: JobRegistry::shouldRetry(),
        );
        $registry = $this->workerRegistry($pool, $logPath, $workersConfig);
        $consumer = new QueueConsumer(
            dispatcher: $dispatcher,
            queue: $queue,
            logPath: $logPath,
            clock: $clock,
            shutdownGrace: 10.0,
            registry: $registry,
            knownIds: $knownIds,
        );

        printf(
            "Consumer started (forwarders=%d, max attempts=%d, retry delay=%ds, visibility=%ds). SIGTERM/SIGINT to stop.\n",
            $queueConfig['consumers'],
            $queueConfig['max_attempts'],
            (int) $queueConfig['retry_delay'],
            (int) $workersConfig['task_timeout'],
        );

        $consumer->run();

        // PLAN Step 21, observable: run() has just stopped accepting new
        // work and stopped pulling new jobs on its own signal-driven thread,
        // and the dispatcher shutdown that ended it finished executing the
        // jobs still running, drained the workers (idle out, busy left
        // alone) and stopped them before returning. What remains here is the
        // tail of the same sequence - close resources, verify, exit.
        printf("Consumer stopped.\n");
        printf(
            "  shutdown: no new work -> no new pulls -> finish executing -> drain workers -> stop workers -> close resources\n",
        );

        // The final snapshot: the workers' last states after the shutdown
        // drained them (DRAINING/STOPPING/DEAD) are what the file keeps.
        $registry->write();

        $counters = $metrics->getCounters();
        printf(
            "  completed=%d failed=%d retried=%d\n",
            $counters[MetricsCollector::JOBS_COMPLETED] ?? 0,
            $counters[MetricsCollector::JOBS_FAILED] ?? 0,
            $counters[MetricsCollector::JOBS_RETRIED] ?? 0,
        );

        $cleanShutdown = $this->verifyNoJobLost($logPath);

        $database->close();
        $this->stopWorkerPoolIfOwned($ownsWorkerPool);
        $this->stopCacheServerIfOwned($ownsCacheServer);
        $this->stopDatabaseServerIfOwned($ownsDatabaseServer, $databaseConfig);

        return $cleanShutdown ? 0 : 1;
    }

    /**
     * PLAN Step 21's "verify that jobs are not silently lost": after the
     * consumer drained, every row the append-only journal holds must still
     * be there and in a state that is either already finished (a terminal
     * outcome) or can be picked up again by a restart (recoverable). The
     * journal is append-only, so a row can only be lost if the platform
     * stopped tracking it - this check turns that invariant into an exit
     * code instead of an assumption, and into the test that pins it.
     */
    private function verifyNoJobLost(string $logPath): bool
    {
        $rows = new QueueJournal($logPath)->rows();

        $terminal = 0;
        $recoverable = 0;
        $lost = 0;

        foreach ($rows as $row) {
            $state = $row['state'];

            if (in_array($state, ['COMPLETED', 'FAILED'], true)) {
                $terminal++;
            } elseif (in_array($state, ['READY', 'PROCESSING', 'DELAYED'], true)) {
                $recoverable++;
            } else {
                $lost++;
            }
        }

        printf(
            "  shutdown verification: journal rows=%d terminal=%d recoverable=%d lost=%d\n",
            count($rows),
            $terminal,
            $recoverable,
            $lost,
        );

        if ($lost > 0) {
            fwrite(STDERR, sprintf("  %d job(s) lost at shutdown%s", $lost, PHP_EOL));
        }

        return $lost === 0;
    }

    /**
     * The Step 12 workload: publish N READY jobs, run them through the real
     * queue → consumer → worker-pool → job path with a fixed-size pool, and
     * report total time, throughput, latencies and utilization. Run a few
     * combinations side by side (100/4, 1000/4, 1000/8) to see that doubling
     * the workers does not halve the time.
     *
     * @param list<string> $args
     */
    private function queueBenchmark(array $args): int
    {
        $jobs = isset($args[0]) ? (int) $args[0] : 100;
        $workers = isset($args[1]) ? (int) $args[1] : 4;

        if ($jobs < 1 || $jobs > 5000) {
            fwrite(STDERR, "jobs must be between 1 and 5000.\n");

            return 1;
        }

        if ($workers < 1 || $workers > 16) {
            fwrite(STDERR, "workers must be between 1 and 16.\n");

            return 1;
        }

        $config = $this->config();
        $databaseConfig = $config['database'];
        $cacheConfig = $config['cache'];

        $database = Database::connect($databaseConfig);

        $ownsDatabaseServer = false;
        $ownsCacheServer = false;

        try {
            $ownsDatabaseServer = $this->ensureDatabaseServer($databaseConfig);
            Migrator::migrate($database);
            $ownsCacheServer = $this->ensureCacheServer($cacheConfig);
        } catch (RuntimeException $e) {
            fwrite(STDERR, $e->getMessage() . PHP_EOL);
            $database->close();
            $this->stopCacheServerIfOwned($ownsCacheServer);
            $this->stopDatabaseServerIfOwned($ownsDatabaseServer, $databaseConfig);

            return 1;
        }

        // An isolated pool with exactly $workers processes on its own socket,
        // so the benchmark controls the parallelism it is measuring.
        $benchDir = sys_get_temp_dir() . '/php-systems-platform/bench-' . uniqid('', true);

        if (!is_dir($benchDir) && !@mkdir($benchDir, 0o777, true) && !is_dir($benchDir)) {
            fwrite(STDERR, sprintf('Could not create benchmark directory "%s".', $benchDir) . PHP_EOL);
            $database->close();
            $this->stopCacheServerIfOwned($ownsCacheServer);
            $this->stopDatabaseServerIfOwned($ownsDatabaseServer, $databaseConfig);

            return 1;
        }

        $logPath = $benchDir . '/queue.log';
        $socketPath = '/tmp/php-bench-' . uniqid('', true) . '.sock';

        $master = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/bin/worker.php'],
            [
                1 => ['file', $benchDir . '/worker.out', 'a'],
                2 => ['file', $benchDir . '/worker.err', 'a'],
            ],
            $pipes,
            null,
            [
                'WORKER_POOL_SOCKET' => $socketPath,
                'WORKER_POOL_MIN' => (string) $workers,
                'WORKER_POOL_MAX' => (string) $workers,
                // A benchmark pushes thousands of jobs through a small pool;
                // the default 5s task timeout would fail jobs queued behind a
                // burst. Give the pool the full 30s the forwarders wait.
                'WORKER_POOL_TIMEOUT' => '30',
            ],
        );

        if (!is_resource($master)) {
            fwrite(STDERR, "Could not start the benchmark worker pool.\n");
            $database->close();
            $this->stopCacheServerIfOwned($ownsCacheServer);
            $this->stopDatabaseServerIfOwned($ownsDatabaseServer, $databaseConfig);

            return 1;
        }

        $benchmark = new QueueBenchmark(
            logPath: $logPath,
            socketPath: $socketPath,
            forwarders: $workers,
        );

        if (!$this->waitForSocket($socketPath)) {
            proc_terminate($master);
            proc_close($master);
            fwrite(STDERR, sprintf('Benchmark pool did not start listening on "%s" in time.', $socketPath) . PHP_EOL);
            $database->close();
            $this->stopCacheServerIfOwned($ownsCacheServer);
            $this->stopDatabaseServerIfOwned($ownsDatabaseServer, $databaseConfig);

            return 1;
        }

        try {
            $metrics = $benchmark->run($jobs, $workers);
        } catch (RuntimeException $e) {
            fwrite(STDERR, $e->getMessage() . PHP_EOL);
            proc_terminate($master);
            proc_close($master);
            $database->close();
            $this->stopCacheServerIfOwned($ownsCacheServer);
            $this->stopDatabaseServerIfOwned($ownsDatabaseServer, $databaseConfig);

            return 1;
        }

        printf("Queue benchmark: %d jobs, %d pool workers\n", $metrics['jobs'], $metrics['workers']);
        printf("  total processing time  %.4fs\n", $metrics['wall_seconds']);
        printf("  throughput             %.1f jobs/s\n", $metrics['throughput_per_sec']);
        printf("  average latency        %.2f ms\n", $metrics['avg_latency_ms']);
        printf("  p95 latency            %.2f ms\n", $metrics['p95_latency_ms']);
        printf("  queue depth            %d\n", $metrics['queue_depth']);
        printf("  worker utilization     %.1f%%\n", $metrics['worker_utilization'] * 100);

        proc_terminate($master);
        proc_close($master);
        $database->close();
        $this->stopCacheServerIfOwned($ownsCacheServer);
        $this->stopDatabaseServerIfOwned($ownsDatabaseServer, $databaseConfig);

        return 0;
    }

    /**
     * PLAN Steps 13 and 14's measurement: the same order snapshot loaded by
     * every execution model the platform has - sequential, forked (the raw
     * primitive: one process per part), pooled (workers that already exist).
     *
     * Two passes, deliberately. The first loads three local catalog rows,
     * where a round trip per part costs more than the overlap saves - the
     * case the plan warns about ("do not add concurrency merely because it
     * is possible"). The second gives every part a simulated external
     * dependency, which is the case the fan-out exists for: three waits that
     * happen at the same time instead of one after another.
     *
     * The order itself is created here, for a seeded customer and the
     * default sku, so the command needs nothing but the platform's own
     * infrastructure.
     *
     * @param list<string> $args rounds, simulated latency per part in ms
     */
    private function ordersCompare(array $args): int
    {
        $rounds = isset($args[0]) ? (int) $args[0] : 5;
        $delayMs = isset($args[1]) ? (int) $args[1] : 50;

        if ($rounds < 1 || $rounds > 100) {
            fwrite(STDERR, "rounds must be between 1 and 100.\n");

            return 1;
        }

        if ($delayMs < 1 || $delayMs > 1000) {
            fwrite(STDERR, "delay-ms must be between 1 and 1000.\n");

            return 1;
        }

        $config = $this->config();
        $databaseConfig = $config['database'];
        $workersConfig = $config['workers'];

        $database = Database::connect($databaseConfig);

        $ownsDatabaseServer = false;
        $ownsWorkerPool = false;

        try {
            $ownsDatabaseServer = $this->ensureDatabaseServer($databaseConfig);
            Migrator::migrate($database);
            $ownsWorkerPool = $this->ensureWorkerPoolServer($workersConfig);
        } catch (RuntimeException $e) {
            fwrite(STDERR, $e->getMessage() . PHP_EOL);
            $database->close();
            $this->stopWorkerPoolIfOwned($ownsWorkerPool);
            $this->stopDatabaseServerIfOwned($ownsDatabaseServer, $databaseConfig);

            return 1;
        }

        $orders = new OrderService(new OrderRepository($database));
        $catalog = new CatalogRepository($database);
        $runner = new ConcurrentTaskRunner(new WorkerPoolClient(
            $workersConfig['socket'],
            // A part that sleeps for its simulated dependency must not look
            // like a task timeout.
            max((float) $workersConfig['task_timeout'], $delayMs / 1000 + 5.0),
        ));

        try {
            $order = $orders->createOrder('Ada Lovelace', '19.99');

            $local = new OrderLoadBenchmark([
                'sequential' => new SequentialOrderLoader($orders, $catalog),
                'forked' => new ForkedOrderLoader($orders, $databaseConfig),
                'pooled' => new ConcurrentOrderLoader($orders, $runner),
            ])->run($order->id, $rounds);

            $waiting = new OrderLoadBenchmark([
                'sequential' => new SequentialOrderLoader($orders, $catalog, $delayMs),
                'forked' => new ForkedOrderLoader($orders, $databaseConfig, $delayMs),
                'pooled' => new ConcurrentOrderLoader($orders, $runner, $delayMs),
            ])->run($order->id, $rounds);
        } catch (RuntimeException | ConnectionFailedException $e) {
            fwrite(STDERR, $e->getMessage() . PHP_EOL);
            $database->close();
            $this->stopWorkerPoolIfOwned($ownsWorkerPool);
            $this->stopDatabaseServerIfOwned($ownsDatabaseServer, $databaseConfig);

            return 1;
        }

        printf("Order load comparison: %d rounds, order %s\n\n", $rounds, $order->id);
        printf("  local reads only\n");
        $this->printComparison($local);
        printf("\n  with a %d ms simulated external dependency per part\n", $delayMs);
        $this->printComparison($waiting);
        printf(
            "\nThree local rows are cheaper to read in one process than to hand to three;\n"
            . "the fan-out starts paying once a part actually waits. forked pays a process\n"
            . "and a connection per load, pooled pays them once - same overlap, different\n"
            . "amortization.\n",
        );

        $database->close();
        $this->stopWorkerPoolIfOwned($ownsWorkerPool);
        $this->stopDatabaseServerIfOwned($ownsDatabaseServer, $databaseConfig);

        return 0;
    }

    /**
     * One line per execution model, the first of them the baseline the
     * others are reported against.
     *
     * @param list<array{name: string, ms: float, speedup: float}> $results
     */
    private function printComparison(array $results): void
    {
        foreach ($results as $index => $result) {
            printf(
                "    %-11s %9.3f ms   %s\n",
                $result['name'],
                $result['ms'],
                $index === 0 ? 'baseline' : sprintf('%.2fx', $result['speedup']),
            );
        }
    }

    /**
     * PLAN Step 16: run 1, 2, 4 and 8 workers, each on its own isolated
     * pool, and compare what they cost in memory before any of them writes
     * anything against what they cost once every one of them does.
     *
     * Each count gets a fresh pool on its own socket (like `benchmark`'s
     * isolated pool) rather than reusing one pool resized between rounds -
     * so "8 workers" always means 8 processes that were freshly forked for
     * this measurement, not 8 that inherited an hour of prior traffic.
     *
     * @param list<string> $args elements per worker to hold (default 1,000,000)
     */
    private function workersMemory(array $args): int
    {
        $elements = isset($args[0]) ? (int) $args[0] : 1_000_000;

        if ($elements < 1 || $elements > 5_000_000) {
            fwrite(STDERR, "elements must be between 1 and 5,000,000.\n");

            return 1;
        }

        $benchmark = new WorkerMemoryBenchmark();
        $reports = [];

        foreach ([1, 2, 4, 8] as $workers) {
            $report = $this->runWorkerMemoryRound($benchmark, $workers, $elements);

            if ($report === null) {
                return 1;
            }

            $reports[] = $report;
        }

        printf("Worker memory comparison: %d workers, %s elements held each\n\n", 8, number_format($elements));
        printf(
            "  %7s  %10s  %10s  %10s  %11s  %11s\n",
            'workers',
            'parent',
            'avg before',
            'avg after',
            'total before',
            'total after',
        );

        foreach ($reports as $report) {
            printf(
                "  %7d  %10s  %10s  %10s  %11s  %11s\n",
                $report['workers'],
                $this->formatMegabytes($report['parent_rss']),
                $this->formatMegabytes($report['before_avg_rss']),
                $this->formatMegabytes($report['after_avg_rss']),
                $this->formatMegabytes($report['total_before_rss']),
                $this->formatMegabytes($report['total_after_rss']),
            );
        }

        $first = $reports[0];
        $last = $reports[count($reports) - 1];

        if ($first['total_before_rss'] !== null && $first['total_after_rss'] !== null
            && $last['total_before_rss'] !== null && $last['total_after_rss'] !== null) {
            $beforeGrowth = $last['total_before_rss'] - $first['total_before_rss'];
            $afterGrowth = $last['total_after_rss'] - $first['total_after_rss'];

            printf(
                "\nGoing from 1 to 8 workers grew total RSS by %s before any of them wrote\n"
                . "anything, and by %s once each held its own copy of the same data - %s more\n"
                . "than adding workers alone accounts for. That gap is %d private copies of\n"
                . "one array a thread or coroutine pool would only ever have held once.\n"
                . "(RSS is summed per process here, so even the 'before' total already double-\n"
                . "counts pages every worker still shares with its parent; it is the growth\n"
                . "between the two totals that isolates what writing actually cost.)\n",
                $this->formatMegabytes($beforeGrowth),
                $this->formatMegabytes($afterGrowth),
                $this->formatMegabytes($afterGrowth - $beforeGrowth),
                $last['workers'] - $first['workers'],
            );
        }

        return 0;
    }

    /**
     * One isolated pool of $workers processes, measured and torn down
     * before returning - or null (with the error already on STDERR) if any
     * step of that failed.
     *
     * @return array{workers: int, workers_observed: int, parent_rss: ?int, before_avg_rss: ?int, before_min_rss: ?int, before_max_rss: ?int, after_avg_rss: ?int, after_min_rss: ?int, after_max_rss: ?int, total_before_rss: ?int, total_after_rss: ?int}|null
     */
    private function runWorkerMemoryRound(WorkerMemoryBenchmark $benchmark, int $workers, int $elements): ?array
    {
        $dir = sys_get_temp_dir() . '/php-systems-platform/memdemo-' . uniqid('', true);

        if (!is_dir($dir) && !@mkdir($dir, 0o777, true) && !is_dir($dir)) {
            fwrite(STDERR, sprintf('Could not create "%s".', $dir) . PHP_EOL);

            return null;
        }

        $socketPath = '/tmp/php-memdemo-' . uniqid('', true) . '.sock';

        $pool = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/bin/worker.php'],
            [
                1 => ['file', $dir . '/worker.out', 'a'],
                2 => ['file', $dir . '/worker.err', 'a'],
            ],
            $pipes,
            null,
            [
                'WORKER_POOL_SOCKET' => $socketPath,
                'WORKER_POOL_MIN' => (string) $workers,
                'WORKER_POOL_MAX' => (string) $workers,
                'WORKER_POOL_TIMEOUT' => '30',
            ],
        );

        if (!is_resource($pool)) {
            fwrite(STDERR, sprintf('Could not start a %d-worker pool.', $workers) . PHP_EOL);

            return null;
        }

        if (!$this->waitForSocket($socketPath)) {
            proc_terminate($pool);
            proc_close($pool);
            fwrite(STDERR, sprintf('The %d-worker pool did not start listening in time.', $workers) . PHP_EOL);

            return null;
        }

        $status = proc_get_status($pool);
        $runner = new ConcurrentTaskRunner(new WorkerPoolClient($socketPath, 30.0));

        try {
            $report = $benchmark->run($runner, $workers, $elements, (int) $status['pid']);
        } catch (RuntimeException $e) {
            fwrite(STDERR, $e->getMessage() . PHP_EOL);
            $report = null;
        }

        proc_terminate($pool);
        proc_close($pool);

        return $report;
    }

    private function formatMegabytes(?int $bytes): string
    {
        return $bytes === null ? 'n/a' : sprintf('%.1fM', $bytes / 1_048_576);
    }

    /**
     * PLAN Step 15: fork a child over a shared array and print how its
     * memory footprint moves in three stages - before the fork (the
     * parent), right after it (the child, still sharing pages), and after
     * the child writes (copy-on-write has run). Needs no platform
     * infrastructure - no database, cache, queue or pool - so it is the one
     * command safe to run entirely on its own.
     */
    private function memoryDemo(): int
    {
        printf("Fork + copy-on-write demo\n\n");

        $result = new ForkedMemoryDemo()->run();

        $this->printMemorySnapshot('before fork    (parent)', $result->beforeFork);
        $this->printMemorySnapshot('after fork     (child) ', $result->afterFork, $result->beforeFork);
        $this->printMemorySnapshot('after modification (child)', $result->afterModification, $result->afterFork);

        printf(
            "\nRight after fork the child's RSS tracks its parent's - the pages are still\n"
            . "shared, nothing was copied. Once the child writes, private memory grows: the\n"
            . "kernel copied exactly the pages that write touched. That growth is\n"
            . "copy-on-write, measured rather than asserted.\n",
        );

        return 0;
    }

    /**
     * PLAN Step 20 end to end: two orders, delivered twice each. The first
     * pair has neither a key nor a guard, so the second delivery settles the
     * order again - one unit of stock gone per delivery, the double-apply any
     * at-least-once queue leaves unguarded handlers open to. The second pair
     * runs the same two deliveries under the idempotency guard: the first
     * delivery records the key, the second (a fresh executor, the way a
     * restarted worker sees the store) reads it back and skips.
     *
     * Needs the database server the way serve does - starts one and migrates
     * it if none answers, and only tears down what this process itself
     * started. The cache is optional: a dead one is counted as a bypass, the
     * same tolerance the jobs themselves have.
     */
    private function idempotencyDemo(): int
    {
        printf("At-least-once vs exactly-once\n\n");

        $config = $this->config();
        $databaseConfig = $config['database'];

        $ownsDatabaseServer = false;

        try {
            $ownsDatabaseServer = $this->ensureDatabaseServer($databaseConfig);

            $database = Database::connect($databaseConfig);
            Migrator::migrate($database);
        } catch (RuntimeException $e) {
            fwrite(STDERR, $e->getMessage() . PHP_EOL);

            return 1;
        }

        try {
            $orders = new OrderService(new OrderRepository($database));
            $catalog = new CatalogRepository($database);
            $cacheConfig = $config['cache'];
            $producer = new Producer(
                new InMemoryQueue(new SystemClock()),
                new JobFactory(new SystemClock(), new MetricsCollector()),
            );

            printf("Scenario: an order completes, and settling takes one unit of stock.\n");
            printf("The settle must happen exactly once; a redelivery must not settle again.\n\n");

            $first = $orders->createOrder('Idempotency Demo', 19.99);
            $stockOf = static fn (string $sku): int => (int) ($catalog->findStock($sku)->available ?? 0);

            printf("1. Without a guard (no key):\n");
            printf("   order    %s\n", $first->id);
            printf("   status   completed, one unit settled\n");
            $before = $stockOf($first->product);

            $unguarded = new JobExecutor($databaseConfig, $cacheConfig);
            $unguarded($producer->dispatch(OrderProcessJob::TYPE, ['order_id' => $first->id], maxAttempts: 3));
            $afterFirst = $stockOf($first->product);

            // A redelivered copy of the same operation - the at-least-once
            // promise kept, exactly as a restart would deliver it.
            $unguarded($producer->dispatch(OrderProcessJob::TYPE, ['order_id' => $first->id], maxAttempts: 3));
            $afterSecond = $stockOf($first->product);

            printf("   stock    %d -> %d -> %d   (each delivery took a unit)\n", $before, $afterFirst, $afterSecond);
            printf("   => a second delivery of the same operation changed the state again.\n\n");

            $second = $orders->createOrder('Idempotency Demo', 19.99);
            $key = sprintf('order.process:%s', $second->id);

            printf("2. With an idempotency key and the guard:\n");
            printf("   order    %s\n", $second->id);
            printf("   key      %s\n", $key);

            $store = sprintf(
                '%s/php-systems-platform-idem-demo-%s.log',
                sys_get_temp_dir(),
                (string) uniqid('', true),
            );
            $before = $stockOf($second->product);

            $firstWorker = new JobExecutor($databaseConfig, $cacheConfig, $store);
            $firstWorker($producer->dispatch(
                OrderProcessJob::TYPE,
                ['order_id' => $second->id],
                maxAttempts: 3,
                idempotencyKey: $key,
            ));
            $afterFirst = $stockOf($second->product);

            // A redelivery is served by a fresh worker; the guard reads the
            // store the first worker wrote and remembers the operation.
            $secondWorker = new JobExecutor($databaseConfig, $cacheConfig, $store);
            $secondWorker($producer->dispatch(
                OrderProcessJob::TYPE,
                ['order_id' => $second->id],
                maxAttempts: 3,
                idempotencyKey: $key,
            ));
            $afterSecond = $stockOf($second->product);

            printf("   stock    %d -> %d -> %d   (second delivery skipped)\n", $before, $afterFirst, $afterSecond);
            printf("   store    %s\n", $store);
            printf(
                "   => with the guard the same two deliveries settle exactly once.\n\n",
            );

            printf(
                "Why: the queue promises at-least-once, not exactly-once. A worker that\n"
                . "settles an order and dies before its acknowledgement leaves the job\n"
                . "PROCESSING; on restart it returns to READY and is delivered again. The\n"
                . "guard deduplicates by the operation key, which is why the key names the\n"
                . "operation - not the delivery. And it is still at-least-once: a crash\n"
                . "between the settle and recording the key re-runs it, exactly as the\n"
                . "unguarded pair above did. Closing that last window needs the side effect\n"
                . "and the record to commit together - a property of the storage, not of\n"
                . "the queue.\n",
            );

            return 0;
        } finally {
            $database->close();

            if ($ownsDatabaseServer) {
                $this->stopDatabaseServerIfOwned(true, $databaseConfig);
            }
        }
    }

    /**
     * PLAN Step 22's failure scenarios, reproduced end to end (the step's
     * own "Test:" sections):
     *
     *     worker crashes -> manager detects -> worker removed -> replacement started
     *     job fails -> retry -> failure -> dead/failed state
     *
     * The worker crash runs against a throwaway pool on its own socket, so
     * it can prove the sequence without disturbing anything already
     * running; the failing job runs through the real dispatcher machinery
     * (journal -> forwarders -> JobExecutor -> registry) on a fresh journal.
     * Failure injection is armed only in development/demo environments
     * (config failure_injection.enabled); in a production environment the
     * command refuses instead of pretending a kill-and-replace is something
     * a production platform reproduces on demand.
     */
    private function failureDemo(): int
    {
        $config = $this->config();

        if (!$config['failure_injection']['enabled']) {
            fwrite(STDERR, "Failure injection is disabled (PLATFORM_ENV is not a development/demo environment).\n");
            fwrite(STDERR, "Run it armed, e.g. PLATFORM_ENV=dev php bin/platform.php failure:demo\n");

            return 1;
        }

        printf("Failure injection (development mode)\n\n");

        $crashExit = $this->failureDemoWorkerCrash();
        $jobExit = $this->failureDemoFailingJob();

        return $crashExit === 0 && $jobExit === 0 ? 0 : 1;
    }

    /**
     * The PLAN worker-crash sequence, against a two-worker pool this command
     * spawns and owns for the duration.
     */
    private function failureDemoWorkerCrash(): int
    {
        $dir = sys_get_temp_dir() . '/php-systems-platform/failuredemo-' . uniqid('', true);

        if (!is_dir($dir) && !@mkdir($dir, 0o777, true) && !is_dir($dir)) {
            fwrite(STDERR, sprintf('Could not create "%s".', $dir) . PHP_EOL);

            return 1;
        }

        $socketPath = '/tmp/php-failuredemo-' . uniqid('', true) . '.sock';

        $pool = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/bin/worker.php'],
            [
                1 => ['file', $dir . '/worker.out', 'a'],
                2 => ['file', $dir . '/worker.err', 'a'],
            ],
            $pipes,
            null,
            [
                'WORKER_POOL_SOCKET' => $socketPath,
                'WORKER_POOL_MIN' => '2',
                'WORKER_POOL_MAX' => '2',
                'WORKER_POOL_TIMEOUT' => '5',
            ],
        );

        if (!is_resource($pool)) {
            fwrite(STDERR, 'Could not start the demo worker pool.' . PHP_EOL);

            return 1;
        }

        if (!$this->waitForSocket($socketPath)) {
            proc_terminate($pool);
            proc_close($pool);
            fwrite(STDERR, 'The demo worker pool did not start listening in time.' . PHP_EOL);

            return 1;
        }

        $exit = 1;

        try {
            $injector = new WorkerFailureInjector(new WorkerPoolClient($socketPath, 5.0));
            $report = $injector->crashOneWorker();

            printf("1. A worker crashes.\n");
            printf("   worker crashes        worker.crash SIGKILLed pid %s mid-request\n", $report['crashed_pid'] === null ? '?' : (string) $report['crashed_pid']);
            printf("   manager detects       %s (%s, %.1f ms)\n", $report['crash_detected'] ? 'yes' : 'no', $report['error'], $report['detected_ms']);
            printf("   worker removed        %s (%.1f ms after the crash)\n", $report['worker_removed'] ? 'yes' : 'no', $report['removed_ms'] ?? 0.0);
            printf("   replacement started   %s (new pid %s, %.1f ms after the crash)\n", $report['replacement_started'] ? 'yes' : 'no', $report['replacement_pid'] ?? '?', $report['replaced_ms'] ?? 0.0);
            printf("   pool size             %d workers (back to configured)\n\n", $report['pool_size']);

            $exit = $report['crash_detected'] && $report['worker_removed'] && $report['replacement_started'] ? 0 : 1;
        } finally {
            proc_terminate($pool);
            proc_close($pool);
        }

        return $exit;
    }

    /**
     * The PLAN job-failure sequence: publish demo.failing onto a fresh
     * journal and run the real dispatcher machinery until the journal shows
     * the job dead. Needs the database itself only because the JobExecutor
     * a forwarder runs connects eagerly - the job that fails never touches
     * it.
     */
    private function failureDemoFailingJob(): int
    {
        $config = $this->config();
        $databaseConfig = $config['database'];
        $cacheConfig = $config['cache'];

        $ownsDatabaseServer = false;
        $ownsCacheServer = false;
        $database = null;

        try {
            $ownsDatabaseServer = $this->ensureDatabaseServer($databaseConfig);
            $database = Database::connect($databaseConfig);
            Migrator::migrate($database);
            $ownsCacheServer = $this->ensureCacheServer($cacheConfig);
        } catch (RuntimeException $e) {
            fwrite(STDERR, $e->getMessage() . PHP_EOL);
            $database?->close();
            $this->stopCacheServerIfOwned($ownsCacheServer);
            $this->stopDatabaseServerIfOwned($ownsDatabaseServer, $databaseConfig);

            return 1;
        }

        $exit = 1;

        try {
            $logPath = sys_get_temp_dir() . '/php-systems-platform/failingjob-' . uniqid('', true) . '.log';
            $clock = new SystemClock();

            $job = new Producer(
                new InMemoryQueue($clock, new FileStorage($logPath)),
                new JobFactory($clock, new MetricsCollector()),
            )->dispatch(FailingJob::TYPE, [], maxAttempts: (int) $config['queue']['max_attempts']);

            $queue = InMemoryQueue::restoreFromStorage(new FileStorage($logPath), $clock);
            $executor = new JobExecutor($databaseConfig, $cacheConfig);
            $pool = new WorkerPool(
                size: 2,
                handler: static fn (Job $job): mixed => $executor->__invoke($job),
            );
            $dispatcher = new JobDispatcher(
                queue: $queue,
                workerPool: $pool,
                retryPolicy: new FixedDelayRetry((int) $config['queue']['retry_delay']),
                clock: $clock,
                visibilityTimeout: (int) $config['workers']['task_timeout'],
                storage: new FileStorage($logPath),
                metrics: new MetricsCollector(),
                shouldRetry: JobRegistry::shouldRetry(),
            );

            $dispatcher->start();

            printf("2. A job keeps failing.\n");
            printf("   published demo.failing (%s, max %d attempts)\n", $job->getId(), $job->getMaxAttempts());

            $journal = new QueueJournal($logPath);
            $lastAttempts = 0;

            // dispatchNext() returns false whenever nothing can move right
            // now - including the retry delay a just-failed job sits out
            // before it is visible again. So this is not a while(dispatch)
            // loop (that would stop right after the first failure); it is a
            // poll until the journal shows the job reached a terminal state.
            $deadline = microtime(true) + 30.0;

            while (microtime(true) < $deadline) {
                if ($dispatcher->dispatchNext()) {
                    $row = $journal->rows()[(string) $job->getId()] ?? [];
                    $attempts = (int) ($row['attempts'] ?? 0);

                    if ($attempts !== $lastAttempts) {
                        printf("   attempt %d -> failed\n", $attempts);
                        $lastAttempts = $attempts;
                    }

                    continue;
                }

                $state = (string) ($journal->rows()[(string) $job->getId()]['state'] ?? '');

                if ($state === 'FAILED' || $state === 'COMPLETED') {
                    break;
                }

                usleep(10_000);
            }

            $pool->shutdown();

            $row = $journal->rows()[(string) $job->getId()] ?? [];
            $snapshot = $journal->snapshot();
            $state = (string) ($row['state'] ?? '?');

            printf("   all %d attempts failed -> %s (dead state)\n", (int) ($row['attempts'] ?? 0), $state);
            printf("   last error            %s\n", (string) ($row['lastError'] ?? '-'));
            printf("   journal counters      failed=%d retried=%d depth=%d\n", $snapshot['failed'], $snapshot['retried'], $snapshot['depth']);
            printf(
                "   => retry worked: a well-formed but hopeless job spent its budget\n"
                . "      and was retired into the FAILED state instead of retried forever.\n",
            );

            $exit = $state === 'FAILED' && (int) ($row['attempts'] ?? 0) === $job->getMaxAttempts() ? 0 : 1;
        } finally {
            $database->close();

            $this->stopCacheServerIfOwned($ownsCacheServer);

            if ($ownsDatabaseServer) {
                $this->stopDatabaseServerIfOwned(true, $databaseConfig);
            }
        }

        return $exit;
    }

    /**
     * One line: this snapshot's PHP and OS memory, and - once a $previous is
     * given - each field's signed delta from it.
     */
    private function printMemorySnapshot(string $label, MemorySnapshot $snapshot, ?MemorySnapshot $previous = null): void
    {
        printf(
            "  %-26s  php %s   rss %s   shared %s   private memory %s\n",
            $label,
            $this->formatBytes($snapshot->phpUsage, $previous?->phpUsage),
            $this->formatBytes($snapshot->rss, $previous?->rss),
            $this->formatBytes($snapshot->sharedMemory, $previous?->sharedMemory),
            $this->formatBytes($snapshot->privateMemory, $previous?->privateMemory),
        );
    }

    private function formatBytes(?int $bytes, ?int $previous = null): string
    {
        if ($bytes === null) {
            return 'n/a';
        }

        $value = sprintf('%.1fM', $bytes / 1_048_576);

        if ($previous === null) {
            return $value;
        }

        $delta = $bytes - $previous;

        return sprintf('%s (%s%.1fM)', $value, $delta >= 0 ? '+' : '', $delta / 1_048_576);
    }

    /**
     * Build the worker-lifecycle observer for queue:consume's own forwarder
     * pool: it tracks each worker's pid, state and current job, attributes
     * completed/failed jobs from the journal, and writes a JSON snapshot the
     * `workers:status` CLI and `GET /workers` endpoint read.
     *
     * @param array<string, mixed> $workersConfig
     */
    private function workerRegistry(WorkerPool $pool, string $logPath, array $workersConfig): WorkerRegistry
    {
        $dataDir = $workersConfig['data_dir'];

        if (!is_dir($dataDir) && !@mkdir($dataDir, 0o777, true) && !is_dir($dataDir)) {
            throw new RuntimeException(sprintf('Could not create worker data directory "%s".', $dataDir));
        }

        return new WorkerRegistry(
            pool: $pool,
            journal: new QueueJournal($logPath),
            statusPath: $dataDir . '/workers.status.json',
        );
    }

    /**
     * Print the queue consumer's worker lifecycle from the snapshot it keeps
     * writing - the same data `GET /workers` answers with over HTTP.
     */
    private function workersStatus(): int
    {
        $config = $this->config();
        $path = $config['workers']['data_dir'] . '/workers.status.json';

        if (!is_file($path)) {
            printf("No worker status at %s - start the queue consumer (queue:consume) first.\n", $path);

            return 0;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (!is_array($decoded)) {
            fwrite(STDERR, sprintf('Could not parse worker status "%s".', $path) . PHP_EOL);

            return 1;
        }

        printf("Worker status (%s)\n", $path);

        foreach ($decoded as $worker) {
            printf(
                "  id=%d pid=%d state=%s current_job=%s completed=%d failed=%d started_at=%.3f\n",
                (int) $worker['id'],
                (int) $worker['pid'],
                (string) $worker['state'],
                $worker['current_job'] === null ? '-' : (string) $worker['current_job'],
                (int) $worker['tasks_completed'],
                (int) $worker['tasks_failed'],
                (float) $worker['started_at'],
            );
        }

        return 0;
    }

    /**
     * Print the queue counters derived from the journal - the same shape
     * GET /queue/status answers with - pairing the metric names PLAN.md
     * Step 9 asks for (queue.depth / queue.published / queue.completed /
     * queue.failed / queue.retried) with their current values.
     */
    private function queueStatus(): int
    {
        $config = $this->config();
        $logPath = $config['queue']['data_dir'] . '/queue.log';

        $snapshot = new QueueJournal($logPath)->snapshot();

        printf("Queue status (%s)\n", $logPath);
        printf("  queue.depth     %d\n", $snapshot['depth']);
        printf("  queue.published %d\n", $snapshot['published']);
        printf("  queue.completed %d\n", $snapshot['completed']);
        printf("  queue.failed    %d\n", $snapshot['failed']);
        printf("  queue.retried   %d\n", $snapshot['retried']);

        return 0;
    }

    /**
     * PLAN Step 23's CLI side of observability, mirroring GET /metrics: the
     * standard metric snapshot over the same MetricsReporter a serve wires.
     * Without a serve running the registry is empty, so what shows is the
     * pull side - queue journal, live worker pool (when one answers) and
     * this process's own RSS - which a pool that refuses to answer simply
     * omits rather than letting fail the whole read.
     */
    private function metricsCommand(): int
    {
        $config = $this->config();

        $reporter = new MetricsReporter(
            new MetricsRegistry(),
            new QueueJournal($config['queue']['data_dir'] . '/queue.log'),
            new WorkerPoolClient(
                $config['workers']['socket'],
                (float) $config['workers']['task_timeout'],
            ),
            new MemoryReporter(),
        );

        $lines = [];

        foreach ($reporter->snapshot() as $name => $value) {
            if (is_float($value)) {
                $value = sprintf('%.4f', $value);
            }

            $lines[] = sprintf('%s %s', $name, $value);
        }

        echo implode(PHP_EOL, $lines) . PHP_EOL;

        return 0;
    }

    /**
     * PLAN Step 24's read side: the whole trace chain for one request_id,
     * from the same JSONL journal serve and every pool worker write into.
     * The serve's own http.request / db.* spans and each worker's
     * job.execute span are one contiguous answer to the demo's four
     * questions - which request created the job, which worker ran it, how
     * long it took, and how many retries it burned.
     *
     * @param list<string> $args
     */
    private function traceCommand(array $args): int
    {
        $requestId = $args[0] ?? null;

        if (!is_string($requestId) || $requestId === '') {
            fwrite(STDERR, "usage: php bin/platform.php trace <request_id>\n");

            return 1;
        }

        $config = $this->config();
        $spans = new Trace($this->traceStorePath($config))->readLog($requestId);

        if ($spans === []) {
            echo sprintf("No spans recorded for %s.\n", $requestId);

            return 0;
        }

        foreach ($spans as $span) {
            $meta = is_array($span['meta'] ?? null) ? $span['meta'] : [];
            $fields = '';

            if (($span['job_id'] ?? null) !== null) {
                $fields .= sprintf(' job=%s', (string) $span['job_id']);
            }

            if (($span['worker_pid'] ?? null) !== null) {
                $fields .= sprintf(' worker_pid=%d', (int) $span['worker_pid']);
            }

            if (($span['attempt'] ?? null) !== null) {
                $fields .= sprintf(' attempt=%d', (int) $span['attempt']);
            }

            $detail = '';

            if (isset($meta['method'], $meta['path'], $meta['status'])) {
                $detail .= sprintf(' %s %s -> %d', (string) $meta['method'], (string) $meta['path'], (int) $meta['status']);
            }

            if (isset($meta['outcome'])) {
                $detail .= sprintf(' %s', (string) $meta['outcome']);

                if (isset($meta['error'])) {
                    $detail .= sprintf(' (%s)', (string) $meta['error']);
                }
            }

            printf(
                "%-13s %7.4fs%s%s%s\n",
                (string) $span['operation'],
                (float) $span['duration'],
                $detail,
                $fields,
                ' ' . (string) $span['request_id'],
            );
        }

        return 0;
    }

    /**
     * The trace journal path: `jobs.trace_store` when the config declares
     * one, nothing when it does not - a config predating Step 24 runs the
     * platform with tracing simply off.
     *
     * @param array<string, mixed> $config
     */
    private function traceStorePath(array $config): ?string
    {
        return isset($config['jobs']['trace_store']) && is_string($config['jobs']['trace_store'])
            ? $config['jobs']['trace_store']
            : null;
    }

    /**
     * PLAN Step 25's whole-platform view: one command that shows every
     * component's state and headline numbers, laid out exactly as PLAN.md's
     * example prints it. The simplest way to see the whole platform.
     *
     * Three kinds of source back the sections:
     *
     *   a running serve's GET /metrics - the HTTP, cache and database
     *                                    counters and the master process's
     *                                    own RSS exist only inside serve, so
     *                                    they are read over HTTP when a
     *                                    serve is answering
     *   live probes                    - a TCP connect to each server's port
     *                                    and one stats round-trip to the pool
     *                                    tell running from stopped
     *   the durable queue journal      - the queue.* counts, read exactly the
     *                                    way queue:status and GET
     *                                    /queue/status read them
     *
     * Every source is optional and none failing is an error: a stopped
     * platform IS what this command is for. Counters that only live in a
     * serve that is not answering print as 0 (process.rss reads as n/a),
     * while the pool- and journal-backed sections stay truthful on their own.
     */
    private function statusCommand(): int
    {
        $config = $this->config();
        $http = $config['http'];
        $databaseConfig = $config['database'];
        $cacheConfig = $config['cache'];
        $workersConfig = $config['workers'];

        $metrics = $this->statusMetrics($http);
        $httpRunning = $metrics !== null;
        $databaseRunning = $this->waitForPort((string) $databaseConfig['host'], (int) $databaseConfig['port'], 0.3);
        $cacheRunning = $this->waitForPort((string) $cacheConfig['host'], (int) $cacheConfig['port'], 0.3);
        [$poolRunning, $workers] = $this->statusWorkerStats($workersConfig);
        $queue = new QueueJournal($config['queue']['data_dir'] . '/queue.log')->snapshot();

        printf("PHP Systems Platform\n--------------------\n\n");

        $this->printStatusSection('HTTP Server', [
            'status' => $httpRunning ? 'running' : 'stopped',
            'requests' => $this->formatStatusCount($this->statusMetricInt($metrics, 'http.requests')),
            'errors' => $this->formatStatusCount($this->statusMetricInt($metrics, 'http.errors')),
        ]);

        $this->printStatusSection('Cache', [
            'status' => $cacheRunning ? 'running' : 'stopped',
            'hits' => $this->formatStatusCount($this->statusMetricInt($metrics, 'cache.hit')),
            'misses' => $this->formatStatusCount($this->statusMetricInt($metrics, 'cache.miss')),
        ]);

        $this->printStatusSection('Database', [
            'status' => $databaseRunning ? 'running' : 'stopped',
            'operations' => $this->formatStatusCount($this->statusMetricInt($metrics, 'db.operations')),
        ]);

        $this->printStatusSection('Queue', [
            'status' => $poolRunning ? 'running' : 'stopped',
            'depth' => $this->formatStatusCount($queue['depth']),
            'processed' => $this->formatStatusCount($queue['completed']),
            'failed' => $this->formatStatusCount($queue['failed']),
        ]);

        $this->printStatusSection('Workers', [
            'total' => $this->formatStatusCount($workers['active'] + $workers['failed']),
            'idle' => $this->formatStatusCount($workers['idle']),
            'busy' => $this->formatStatusCount($workers['busy']),
            'failed' => $this->formatStatusCount($workers['failed']),
        ]);

        // master = the serve process itself (process.rss, which serve
        // reports about itself over /metrics); workers RSS is the pool's
        // average, taken from /metrics or computed from the same stats when
        // no serve is answering.
        $this->printStatusSection('Memory', [
            'master RSS' => $this->formatMegabytes($this->statusMetricInt($metrics, 'process.rss')),
            'workers RSS' => $this->formatMegabytes($this->statusMetricInt($metrics, 'worker.rss') ?? $workers['rss']),
        ]);

        return 0;
    }

    /**
     * One status section exactly as PLAN.md prints it: a heading line, then
     * one row per fact with the labels right-padded to one column so every
     * value aligns.
     *
     * @param array<string, string> $rows
     */
    private function printStatusSection(string $title, array $rows): void
    {
        printf("%s\n", $title);

        foreach ($rows as $label => $value) {
            printf("  %-14s%s\n", $label . ':', $value);
        }

        echo "\n";
    }

    /**
     * A count with thousands separators when it exists, plain 0 when it
     * does not - the live counters a stopped serve cannot still hold are
     * surfaced as 0 rather than as an error.
     */
    private function formatStatusCount(?int $value): string
    {
        return number_format($value ?? 0);
    }

    /**
     * One metric out of a /metrics dump, when the dump carries it.
     *
     * @param array<string, string>|null $metrics
     */
    private function statusMetricInt(?array $metrics, string $name): ?int
    {
        return $metrics !== null && isset($metrics[$name]) ? (int) $metrics[$name] : null;
    }

    /**
     * Read a running serve's GET /metrics from another process - the only
     * place the http/cache/db counters and the master's own RSS live. One
     * raw HTTP GET over a single connection (the same exercise the vendor
     * component's own bin/client.php demonstrates): "Connection: close"
     * makes "read until EOF" a complete answer, and everything after the
     * blank header/body separator is the metric dump.
     *
     * Null when no serve answers or the answer carries no metric lines, and
     * the caller then falls back to what live probes and the journal still
     * prove.
     *
     * @param array<string, mixed> $http
     *
     * @return array<string, string>|null metric name → value, exactly as the
     *                                 snapshot printed them
     */
    private function statusMetrics(array $http): ?array
    {
        $host = (string) $http['host'];
        $port = (int) $http['port'];
        $socket = @stream_socket_client(sprintf('tcp://%s:%d', $host, $port), $errorCode, $errorMessage, 0.5);

        if ($socket === false) {
            return null;
        }

        stream_set_timeout($socket, 2);

        fwrite($socket, sprintf("GET /metrics HTTP/1.1\r\nHost: %s\r\nConnection: close\r\n\r\n", $host));

        $raw = stream_get_contents($socket);
        fclose($socket);

        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $lines = preg_split('/\R/', $raw);

        if ($lines === false) {
            return null;
        }

        $metrics = [];
        $inBody = false;

        foreach ($lines as $line) {
            if (!$inBody) {
                $inBody = $line === '';

                continue;
            }

            $pair = explode(' ', $line, 2);

            if (count($pair) === 2) {
                $metrics[$pair[0]] = $pair[1];
            }
        }

        return $metrics === [] ? null : $metrics;
    }

    /**
     * Ask the pool for its workers' live tally - the Worker table of PLAN
     * Step 25's example - and whether a Master answered at all. The tally
     * mirrors MetricsReporter's own aggregation (active/busy/idle/failed,
     * average worker RSS) so these numbers and a /metrics read agree, but
     * the two are read independently: a pool working without a serve still
     * answers.
     *
     * @param array<string, mixed> $config
     *
     * @return array{0: bool, 1: array{active: int, busy: int, idle: int, failed: int, rss: int|null}}
     *               whether the pool answered, then the tally
     */
    private function statusWorkerStats(array $config): array
    {
        $client = new WorkerPoolClient((string) $config['socket'], (float) $config['task_timeout']);

        try {
            $stats = $client->stats();
        } catch (\Throwable) {
            return [false, ['active' => 0, 'busy' => 0, 'idle' => 0, 'failed' => 0, 'rss' => null]];
        }

        $client->close();

        $active = 0;
        $busy = 0;
        $idle = 0;
        $failed = 0;
        $samples = [];

        foreach ($stats as $worker) {
            $state = (string) $worker['state'];

            if ($state === 'DEAD') {
                $failed++;

                continue;
            }

            $active++;

            if ($state === 'BUSY') {
                $busy++;
            } elseif ($state === 'IDLE') {
                $idle++;
            }

            if ($worker['memoryBytes'] !== null) {
                $samples[] = (int) $worker['memoryBytes'];
            }
        }

        return [
            true,
            [
                'active' => $active,
                'busy' => $busy,
                'idle' => $idle,
                'failed' => $failed,
                'rss' => $samples === [] ? null : (int) round(array_sum($samples) / count($samples)),
            ],
        ];
    }

    /**
     * PLAN Step 19's job metadata (job_id, attempt, max_attempts,
     * created_at, started_at, completed_at, last_error) - all of it straight
     * off QueueJournal now: started_at/completed_at/last_error are the
     * component's own Job fields (PhpJobQueue\Job\Job, stamped by
     * JobDispatcher on every dispatch and outcome), persisted in the same
     * durable record as everything else the journal already carried. They
     * describe the MOST RECENT delivery only - the component tracks the
     * latest attempt, not a history of every one - which is the one thing
     * the platform's own now-removed per-attempt log used to add on top.
     *
     * @param list<string> $args the job id
     */
    private function queueJob(array $args): int
    {
        $id = $args[0] ?? null;

        if ($id === null) {
            fwrite(STDERR, "Usage: php bin/platform.php queue:job <id>\n");

            return 1;
        }

        $config = $this->config();
        $logPath = $config['queue']['data_dir'] . '/queue.log';
        $rows = new QueueJournal($logPath)->rows();
        $row = $rows[$id] ?? null;

        if ($row === null) {
            fwrite(STDERR, sprintf('No job "%s" in the journal at %s.', $id, $logPath) . PHP_EOL);

            return 1;
        }

        printf("Job %s\n", $id);
        printf("  type          %s\n", (string) $row['type']);
        printf("  state         %s\n", (string) $row['state']);
        printf("  attempts      %d / %d\n", (int) $row['attempts'], (int) $row['maxAttempts']);
        printf("  created_at    %s\n", $this->formatTimestamp((float) $row['createdAt']));

        $startedAt = $row['startedAt'] ?? null;
        $completedAt = $row['completedAt'] ?? null;

        if ($startedAt === null && $completedAt === null) {
            printf("  not started yet\n");

            return 0;
        }

        printf("\n  last attempt\n");
        printf("    started   %s\n", $startedAt !== null ? $this->formatTimestamp((float) $startedAt) : '-');
        printf("    completed %s\n", $completedAt !== null ? $this->formatTimestamp((float) $completedAt) : '-');
        printf("    error     %s\n", $row['lastError'] ?? '-');

        return 0;
    }

    private function formatTimestamp(float $unixTime): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', (int) $unixTime);
    }

    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        return require __DIR__ . '/../../config/platform.php';
    }

    private function notImplemented(string $command): int
    {
        fwrite(STDOUT, sprintf("%s: not implemented yet - coming with the next implementation phase\n", $command));

        return 0;
    }
}
