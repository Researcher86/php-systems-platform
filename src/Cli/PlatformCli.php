<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Cli;

use PhpJobQueue\Dispatcher\JobDispatcher;
use PhpJobQueue\Job\Job;
use PhpJobQueue\Metrics\MetricsCollector;
use PhpJobQueue\Persistence\FileStorage;
use PhpJobQueue\Persistence\JobStorage;
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
use PhpMiniHttpServer\Support\StderrLogger;
use PhpSystemsPlatform\Application\Application;
use PhpSystemsPlatform\Application\ApplicationWiring;
use PhpSystemsPlatform\Application\Handlers\FailWorkerHandler;
use PhpSystemsPlatform\Application\Handlers\HealthHandler;
use PhpSystemsPlatform\Application\Handlers\MetricsHandler;
use PhpSystemsPlatform\Application\Handlers\OrderCreateHandler;
use PhpSystemsPlatform\Application\Handlers\OrderReadHandler;
use PhpSystemsPlatform\Application\Handlers\OrderUpdateHandler;
use PhpSystemsPlatform\Application\Handlers\ParallelHandler;
use PhpSystemsPlatform\Application\Handlers\QueueStatusHandler;
use PhpSystemsPlatform\Application\Handlers\WorkersStatusHandler;
use PhpSystemsPlatform\Benchmarks\LoadTestRunner;
use PhpSystemsPlatform\Cache\CacheService;
use PhpSystemsPlatform\Demo\PlatformDemo;
use PhpSystemsPlatform\Domain\OrderService;
use PhpSystemsPlatform\Domain\SequentialOrderLoader;
use PhpSystemsPlatform\Experiments\FailureExperiments;
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
use PhpSystemsPlatform\Queue\JournalOnlyQueue;
use PhpSystemsPlatform\Queue\QueueConsumer;
use PhpSystemsPlatform\Queue\QueueJournal;
use PhpSystemsPlatform\Storage\Database;
use PhpSystemsPlatform\Storage\Migrator;
use PhpSystemsPlatform\Storage\Repositories\CatalogRepository;
use PhpSystemsPlatform\Storage\Repositories\OrderRepository;
use PhpSystemsPlatform\Support\ShutdownStack;
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
 * sake. Each command is an entry in COMMANDS and an arm in dispatch(). See
 * docs/architecture.md for where each command sits in the process model.
 *
 * Process ownership, shared by every command that needs infrastructure:
 *
 *   database server  daemonizes and is tracked by a pid file, so ownership is
 *                    decided once at start-up (did this call start it?)
 *   cache server,    no daemon mode: spawned as direct children, and the
 *   worker pool      process handle held in a property IS the ownership
 *
 * An already-running server of any kind is adopted and left running. Every
 * release is pushed onto one ShutdownStack as soon as the resource is held,
 * so no exit path can forget one.
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
        'load' => 'Run the Step 29 load tests: load [--requests=N] [--concurrency=N] [--jobs=N] [--workers=1,2,4,8] [--json].',
        'orders:compare' => 'Compare sequential and concurrent order loading: orders:compare <rounds> <delay-ms>.',
        'memory:demo' => 'Demonstrate fork() and copy-on-write memory behavior.',
        'workers:memory' => 'Measure worker process memory (1, 2, 4, 8 workers).',
        'idempotency:demo' => 'Demonstrate at-least-once delivery and the idempotency guard.',
        'failure:demo' => 'Reproduce the failure scenarios end to end.',
        'experiments' => 'Run the Step 30 failure/overload experiments.',
        'metrics' => 'Print the platform\'s standard metric snapshot.',
        'trace' => 'Print the spans recorded for one request_id: trace <request_id>.',
    ];

    /**
     * The cache server and pool Master this process spawned, if any. Neither
     * has a daemon mode, so holding the handle is the proof of ownership: an
     * adopted server was never stored here, and a stop*() on a null handle
     * is a no-op. No separate "owned" flag that could disagree with it.
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

        return $this->dispatch($command, array_slice($argv, 2));
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
     * @param list<string> $args the arguments after the command name
     */
    private function dispatch(string $command, array $args): int
    {
        return match ($command) {
            'serve' => $this->serve(),
            // `worker` is an alias: the consumer is the process that owns the pool.
            'worker', 'queue:consume' => $this->queueConsume(),
            'queue:publish' => $this->queuePublish($args),
            'queue:status' => $this->queueStatus(),
            'queue:job' => $this->queueJob($args),
            'workers:status' => $this->workersStatus(),
            'benchmark' => $this->queueBenchmark($args),
            'load' => $this->loadTests($args),
            'orders:compare' => $this->ordersCompare($args),
            'memory:demo' => $this->memoryDemo(),
            'workers:memory' => $this->workersMemory($args),
            'idempotency:demo' => $this->idempotencyDemo(),
            'failure:demo' => $this->failureDemo(),
            'experiments' => new FailureExperiments()->run(),
            'metrics' => $this->metricsCommand(),
            'trace' => $this->traceCommand($args),
            'status' => $this->statusCommand(),
            'demo' => new PlatformDemo()->run(),
            default => throw new \LogicException(sprintf('Command "%s" is listed but not dispatched.', $command)),
        };
    }

    /**
     * The HTTP server wired to the platform Application, one connection at a
     * time on the component's select loop.
     *
     * Everything serve starts is pushed onto one ShutdownStack in acquisition
     * order and released by the single finally. Before that, two of the error
     * paths forgot the pool Master - and the next serve then adopted the
     * orphan instead of owning it, so nothing ever stopped it.
     */
    private function serve(): int
    {
        $config = $this->config();
        $workersConfig = $config['workers'];
        $shutdown = new ShutdownStack('serve');

        try {
            // One registry every component reports into, and one trace journal
            // shared with the pool workers, so a request's whole span chain
            // (http/db here, job.execute in the workers) reads from one file.
            $systemMetrics = new MetricsRegistry();
            $systemTrace = new Trace($this->traceStorePath($config));

            $database = $this->openDatabase($shutdown, $config['database'], $systemMetrics, $systemTrace);

            $cacheConfig = $config['cache'];
            $cache = CacheService::fromConfig($cacheConfig, $systemMetrics);
            $this->ensureCacheServerIfEnabled($shutdown, $cacheConfig);
            $shutdown->push(static fn (): null => $cache->close(), 'cache');

            $this->ensureWorkerPool($shutdown, $workersConfig);

            // The debug route exists only in development/demo environments;
            // a null injector means application() never registers it.
            $failureInjector = $config['failure_injection']['enabled']
                ? new WorkerFailureInjector($this->poolClient($workersConfig))
                : null;

            // One journal instance for the backpressure policy, /queue/status
            // and /metrics, so they share its replay cache.
            $queueJournal = new QueueJournal($this->queueLogPath($config));
            $logger = new StderrLogger();

            $application = $this->application(new ApplicationWiring(
                database: $database,
                cache: $cache,
                producer: $this->producer($config['queue']),
                runner: new ConcurrentTaskRunner($this->poolClient($workersConfig)),
                queueJournal: $queueJournal,
                workersStatusPath: $this->workersStatusPath($config),
                maxQueueSize: (int) $config['queue']['max_size'],
                failureInjector: $failureInjector,
                metricsReporter: new MetricsReporter(
                    $systemMetrics,
                    $queueJournal,
                    $this->poolClient($workersConfig),
                    new MemoryReporter(),
                ),
                trace: $systemTrace,
                logger: $logger,
            ));

            $http = $config['http'];
            $serverConfig = new ServerConfig(
                host: $http['host'],
                port: $http['port'],
                // An idle connection is reclaimed after request_timeout, one
                // stuck mid-header sooner (the Slowloris guard). The sweep in
                // runHttpLoop() is what enforces both.
                connectionTimeout: (float) $http['request_timeout'],
                headerTimeout: (float) $http['header_timeout'],
            );
            $server = new Server($serverConfig);
            $server->start();

            $this->runHttpLoop($server, $serverConfig, $application, $logger);

            $server->stop();
            printf("Shutdown complete.\n");

            return 0;
        } catch (RuntimeException $e) {
            return $this->fail($e);
        } finally {
            $shutdown->run();
        }
    }

    /**
     * The select loop serve() spends its life in: accept, dispatch, sweep.
     * Returns once SIGINT/SIGTERM stopped the loop.
     */
    private function runHttpLoop(Server $server, ServerConfig $serverConfig, Application $application, StderrLogger $logger): void
    {
        $loop = new SelectLoop();
        $parser = new HttpParser($serverConfig->maxHeaderBytes, $serverConfig->maxBodyBytes);
        $encoder = new ResponseEncoder();
        $metrics = new ServerMetrics();

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

        // Server measures idle time and header time but never acts on them by
        // itself: without this sweep the two timeouts would be config only.
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
    }

    /**
     * The platform's routes. Optional collaborators are null-tolerant: no
     * journal means no backpressure and no /queue/status, and the metrics and
     * debug routes exist only when serve() wired what backs them - a
     * production serve has no /debug/fail-worker route at all.
     */
    private function application(ApplicationWiring $wiring): Application
    {
        $cache = $wiring->cache;
        $journal = $wiring->queueJournal;
        $orders = new OrderService(new OrderRepository($wiring->database), $wiring->producer);

        // Only a real queue has a depth to be overloaded.
        $backpressure = ($journal !== null && $wiring->maxQueueSize !== null)
            ? new BackpressurePolicy($journal, $wiring->maxQueueSize)
            : null;

        $router = new Router();
        $router->get('/health', (new HealthHandler())(...));
        $router->post('/orders', (new OrderCreateHandler($orders, $cache, $backpressure, $wiring->trace))(...));
        $router->get('/orders/{id}', (new OrderReadHandler($orders, $cache))(...));
        $router->put('/orders/{id}', (new OrderUpdateHandler($orders, $cache))(...));
        $router->get('/parallel', (new ParallelHandler($wiring->runner))(...));
        $router->get('/workers', (new WorkersStatusHandler($wiring->workersStatusPath))(...));

        if ($journal !== null) {
            $router->get('/queue/status', (new QueueStatusHandler($journal))(...));
        }

        if ($wiring->metricsReporter !== null) {
            $router->get('/metrics', (new MetricsHandler($wiring->metricsReporter))(...));
        }

        if ($wiring->failureInjector !== null) {
            $router->post('/debug/fail-worker', (new FailWorkerHandler($wiring->failureInjector))(...));
        }

        // The Application opens one request scope per answer (X-Request-ID,
        // the http.request span); a throwing handler is logged on serve's
        // own stream, next to the connection it threw on.
        return new Application($router, $wiring->metricsReporter?->registry(), $wiring->trace, $wiring->logger);
    }

    /**
     * The component's Producer in front of a queue that only journals each
     * push. The log is the source of truth across processes - queue:consume
     * restores it and replays READY jobs - so this side keeps no in-memory
     * copy (see JournalOnlyQueue).
     *
     * @param array<string, mixed> $queueConfig
     */
    private function producer(array $queueConfig): Producer
    {
        $dataDir = $queueConfig['data_dir'];
        $this->ensureDirectory($dataDir, 'queue data directory');

        $clock = new SystemClock();

        return new Producer(
            new JournalOnlyQueue(new FileStorage($dataDir . '/queue.log'), $clock),
            new JobFactory($clock, new MetricsCollector()),
        );
    }

    // ---------------------------------------------------------------------
    // Infrastructure: start-or-adopt, and register the release
    // ---------------------------------------------------------------------

    /**
     * Make sure a database server answers, connect to it and migrate. The
     * server stop is pushed before the client close, so on the way out the
     * client closes while its server is still up.
     *
     * @param array<string, mixed> $databaseConfig
     */
    private function openDatabase(
        ShutdownStack $shutdown,
        array $databaseConfig,
        ?MetricsRegistry $metrics = null,
        ?Trace $trace = null,
    ): Database {
        if ($this->ensureDatabaseServer($databaseConfig)) {
            $shutdown->push(fn (): null => $this->stopDatabaseServer($databaseConfig), 'database server');
        }

        // connect() is lazy (a connection pool), so nothing is dialled yet.
        $database = Database::connect($databaseConfig, 10, $metrics, $trace);
        $shutdown->push(static fn (): null => $database->close(), 'database');

        Migrator::migrate($database);

        return $database;
    }

    /**
     * CACHE_ENABLED=0 is a platform with no cache tier, not one whose cache is
     * down: no server is started, and the printed line is how a load test's
     * output tells the two configurations apart.
     *
     * The stop is registered unconditionally: it releases by handle, so it is
     * a no-op exactly when nothing is owned (disabled or adopted).
     *
     * @param array<string, mixed> $cacheConfig
     */
    private function ensureCacheServerIfEnabled(ShutdownStack $shutdown, array $cacheConfig): void
    {
        if ((bool) ($cacheConfig['enabled'] ?? true)) {
            $this->ensureCacheServer($cacheConfig);
        } else {
            printf("Cache disabled (CACHE_ENABLED): no cache server on tcp://%s:%d\n", $cacheConfig['host'], $cacheConfig['port']);
        }

        $shutdown->push(fn (): null => $this->stopCacheServer(), 'cache server');
    }

    /**
     * @param array<string, mixed> $workersConfig
     */
    private function ensureWorkerPool(ShutdownStack $shutdown, array $workersConfig): void
    {
        $this->ensureWorkerPoolServer($workersConfig);
        $shutdown->push(fn (): null => $this->stopWorkerPool(), 'worker pool');
    }

    /**
     * Start a daemonized database server unless one already answers, and
     * return whether this call started it (and so must stop it).
     *
     * @param array<string, mixed> $config
     */
    private function ensureDatabaseServer(array $config): bool
    {
        $script = $this->binary('minidb.php');
        $pidFile = $config['data_dir'] . '/minidb.pid';
        $output = '';

        // The pid file alone is not enough: it can be stale, or belong to
        // another platform process. The port is the ground truth, and only
        // when neither says "running" may this process start and own one -
        // otherwise two processes would each think they own the same server
        // and the second would stop the first's infrastructure on the way out.
        $hadServer = $this->runServerCli([$script, 'status', '--pid-file', $pidFile], $output) === 0;

        if ($hadServer || $this->waitForPort($config['host'], (int) $config['port'], 0.3)) {
            printf("Database server already running on tcp://%s:%d\n", $config['host'], $config['port']);

            return false;
        }

        $this->ensureDirectory($config['data_dir'], 'database data directory');

        $code = $this->runServerCli([
            $script,
            'start',
            '--host', $config['host'],
            '--port', (string) $config['port'],
            '--data', $config['data_dir'],
            '--daemon',
            '--pid-file', $pidFile,
            '--log-file', $config['data_dir'] . '/minidb.log',
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
    private function stopDatabaseServer(array $config): void
    {
        $output = '';
        $command = [$this->binary('minidb.php'), 'stop', '--pid-file', $config['data_dir'] . '/minidb.pid'];

        if ($this->runServerCli($command, $output) === 0) {
            printf("Database server stopped\n");

            return;
        }

        fwrite(STDERR, 'Database server could not be stopped: ' . rtrim($output) . PHP_EOL);
    }

    /**
     * Run a server's control CLI to completion; $output gets stdout + stderr.
     *
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

    /**
     * Spawn the cache server as a child unless one already answers. Its bin is
     * a foreground server configured through the environment, the same
     * contract as the component's own bin/server.php.
     *
     * @param array<string, mixed> $config
     */
    private function ensureCacheServer(array $config): void
    {
        if ($this->cacheServerAnswers($config)) {
            printf("Cache server already running on tcp://%s:%d\n", $config['host'], $config['port']);

            return;
        }

        $dataDir = $config['data_dir'];
        $this->ensureDirectory($dataDir, 'cache data directory');

        $this->cacheProcess = $this->spawn(
            $this->binary('cache.php'),
            $dataDir . '/cache',
            [
                'CACHE_HOST' => $config['host'],
                'CACHE_PORT' => (string) $config['port'],
                'CACHE_SNAPSHOT' => $dataDir . '/cache.snapshot',
            ],
            'Could not start the cache server process.',
        );

        if (!$this->waitForPort($config['host'], (int) $config['port'])) {
            $this->stopCacheServer();

            throw new RuntimeException(sprintf(
                'Cache server did not start listening on tcp://%s:%d in time.',
                $config['host'],
                $config['port'],
            ));
        }

        printf("Cache server listening on tcp://%s:%d\n", $config['host'], $config['port']);
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

    /** SIGTERM is the cache's graceful shutdown, final snapshot included. */
    private function stopCacheServer(): void
    {
        if ($this->releaseChild($this->cacheProcess)) {
            printf("Cache server stopped\n");
        }
    }

    /**
     * Spawn the pool Master (bin/worker.php) as a child unless a pool already
     * answers on the socket. Kept out of the HTTP process on purpose: the
     * process boundary is what the lab points at.
     *
     * @param array<string, mixed> $config
     */
    private function ensureWorkerPoolServer(array $config): void
    {
        if ($this->workerPoolAnswers($config)) {
            printf("Worker pool already running on %s\n", $config['socket']);

            return;
        }

        $dataDir = $config['data_dir'];
        $this->ensureDirectory($dataDir, 'worker data directory');

        $this->workerProcess = $this->spawn(
            $this->binary('worker.php'),
            $dataDir . '/worker',
            null,
            'Could not start the worker pool process.',
        );

        if (!$this->waitForSocket($config['socket'])) {
            $this->stopWorkerPool();

            throw new RuntimeException(sprintf('Worker pool did not start listening on "%s" in time.', $config['socket']));
        }

        printf("Worker pool listening on %s\n", $config['socket']);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function workerPoolAnswers(array $config): bool
    {
        $client = $this->poolClient($config);

        try {
            $client->call(new WorkerRequest('ping'));
            $client->close();

            return true;
        } catch (ConnectionFailedException | ConnectionClosedException) {
            return false;
        }
    }

    /** SIGTERM is the pool's graceful shutdown: drain in-flight tasks, exit the workers. */
    private function stopWorkerPool(): void
    {
        if ($this->releaseChild($this->workerProcess)) {
            printf("Worker pool stopped\n");
        }
    }

    /**
     * A throwaway pool of exactly $workers processes on its own socket, so a
     * measurement controls the parallelism it measures and never disturbs a
     * pool that is already running. The caller owns the returned handle.
     *
     * @return resource
     */
    private function spawnIsolatedPool(string $logDir, string $socketPath, int $workers, int $timeoutSeconds, string $failure): mixed
    {
        // The environment replaces the child's whole environment.
        return $this->spawn($this->binary('worker.php'), $logDir . '/worker', [
            'WORKER_POOL_SOCKET' => $socketPath,
            'WORKER_POOL_MIN' => (string) $workers,
            'WORKER_POOL_MAX' => (string) $workers,
            'WORKER_POOL_TIMEOUT' => (string) $timeoutSeconds,
        ], $failure);
    }

    /**
     * Start `php $script` as a child with stdout/stderr appended to
     * $logPrefix.out / .err. A null $env inherits this process's environment.
     *
     * @param array<string, string>|null $env
     *
     * @return resource
     */
    private function spawn(string $script, string $logPrefix, ?array $env, string $failure): mixed
    {
        $process = proc_open(
            [PHP_BINARY, $script],
            [
                1 => ['file', $logPrefix . '.out', 'a'],
                2 => ['file', $logPrefix . '.err', 'a'],
            ],
            $pipes,
            null,
            $env,
        );

        if (!is_resource($process)) {
            throw new RuntimeException($failure);
        }

        return $process;
    }

    /**
     * SIGTERM a child, wait for it and clear the handle. False when there was
     * nothing to release.
     *
     * @param resource|null $process
     */
    private function releaseChild(mixed &$process): bool
    {
        if (!is_resource($process)) {
            return false;
        }

        $handle = $process;
        $process = null;
        $this->terminate($handle);

        return true;
    }

    /** @param resource $process */
    private function terminate(mixed $process): void
    {
        proc_terminate($process);
        proc_close($process);
    }

    private function waitForPort(string $host, int $port, float $timeoutSeconds = 10.0): bool
    {
        return $this->waitForEndpoint(sprintf('tcp://%s:%d', $host, $port), $timeoutSeconds);
    }

    private function waitForSocket(string $socketPath, float $timeoutSeconds = 10.0): bool
    {
        return $this->waitForEndpoint(sprintf('unix://%s', $socketPath), $timeoutSeconds);
    }

    /** Poll until something accepts a connection on $address, or the deadline passes. */
    private function waitForEndpoint(string $address, float $timeoutSeconds): bool
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            $socket = @stream_socket_client($address, $errorCode, $errorMessage, 0.2);

            if ($socket !== false) {
                fclose($socket);

                return true;
            }

            usleep(100_000);
        }

        return false;
    }

    // ---------------------------------------------------------------------
    // Queue
    // ---------------------------------------------------------------------

    /**
     * Publish one job into the journal-backed queue, through the same producer
     * serve() uses, so queue:consume handles it exactly like one enqueued over
     * HTTP. The optional key is the idempotency key: publish
     * `order.process <payload> order.process:<id>` twice and the guard
     * deduplicates the second delivery.
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
            $payload = json_decode($args[1], true);

            if (!is_array($payload)) {
                fwrite(STDERR, "Payload must be a JSON object.\n");

                return 1;
            }
        }

        $key = $args[2] ?? null;
        $config = $this->config();

        try {
            $producer = $this->producer($config['queue']);
        } catch (RuntimeException $e) {
            return $this->fail($e);
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
            $this->queueLogPath($config),
        );

        return 0;
    }

    /**
     * The long-running queue consumer (also `worker`).
     *
     * It restores the append-only journal into a queue and dispatches every
     * READY job to the php-worker-pool through WorkerManager: the pool's
     * forked workers run the jobs, so worker lifecycle and failure belong to
     * the pool, not to this process. The QueueConsumer loop re-reads the
     * journal, so jobs published by other processes are picked up on the next
     * pass. SIGTERM/SIGINT stop it gracefully.
     *
     * The jobs need the database and cache, so - like serve() - it starts or
     * adopts those servers and the pool Master, and releases what it owns.
     * The exit code is the "no job silently lost" check on the journal.
     */
    private function queueConsume(): int
    {
        $config = $this->config();
        $queueConfig = $config['queue'];
        $workersConfig = $config['workers'];
        $logPath = $this->queueLogPath($config);

        try {
            $this->ensureDirectory($queueConfig['data_dir'], 'queue data directory');
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        $clock = new SystemClock();
        $storage = new FileStorage($logPath);

        // Every job the journal already knew about is admitted now; the
        // consumer loop then picks up only rows that arrive after this moment.
        // The queue and the known-id set come from ONE read of the journal: a
        // second load() could see a job published in between that the first
        // did not, and the first resync would then push it a second time.
        $rows = $storage->load();
        $knownIds = array_fill_keys(array_keys($rows), true);
        $queue = InMemoryQueue::restoreFromStorage(new class ($storage, $rows) implements JobStorage {
            /** @param array<string, array<string, mixed>> $rows */
            public function __construct(private JobStorage $storage, private array $rows)
            {
            }

            public function store(string $key, array $data): void
            {
                $this->storage->store($key, $data);
            }

            public function load(): array
            {
                return $this->rows;
            }
        }, $clock);

        $restored = new QueueJournal($logPath)->snapshot();
        printf("Consumer restoring queue from %s\n", $logPath);
        printf("  %d published, %d ready/delayed/processing\n", $restored['published'], $restored['depth']);

        $shutdown = new ShutdownStack('queue:consume');

        try {
            $this->openDatabase($shutdown, $config['database']);
            $this->ensureCacheServerIfEnabled($shutdown, $config['cache']);
            $this->ensureWorkerPool($shutdown, $workersConfig);

            // Each job-queue worker is only a forwarder: it hands its job to
            // the pool as a job.execute task and waits for the verdict. The
            // client is built lazily inside the handler so every forked
            // forwarder dials its own connection instead of inheriting the
            // parent's socket.
            $metrics = new MetricsCollector();
            $workerManager = null;
            $pool = new WorkerPool(
                size: (int) $queueConfig['consumers'],
                handler: function (Job $job) use (&$workerManager, $workersConfig): mixed {
                    $workerManager ??= new WorkerManager($this->poolClient($workersConfig));
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
                // Visibility must span the pool's whole task timeout, or a slow
                // job is requeued while a worker is still finishing it.
                visibilityTimeout: (int) $workersConfig['task_timeout'],
                storage: $storage,
                metrics: $metrics,
                // A payload JobRegistry::validate() rejects can never succeed,
                // so it is never retried, however many attempts remain.
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

            // Returns after the signal: no new work, no new pulls, in-flight
            // jobs finished, workers drained and stopped.
            $consumer->run();

            printf("Consumer stopped.\n");
            printf(
                "  shutdown: no new work -> no new pulls -> finish executing -> drain workers -> stop workers -> close resources\n",
            );

            // The final snapshot keeps the workers' last (drained) states.
            $registry->write();

            $counters = $metrics->getCounters();
            printf(
                "  completed=%d failed=%d retried=%d\n",
                $counters[MetricsCollector::JOBS_COMPLETED] ?? 0,
                $counters[MetricsCollector::JOBS_FAILED] ?? 0,
                $counters[MetricsCollector::JOBS_RETRIED] ?? 0,
            );

            // Evaluated before the finally releases the servers, so the check
            // reads the journal as the consumer left it.
            return $this->verifyNoJobLost($logPath) ? 0 : 1;
        } catch (RuntimeException $e) {
            return $this->fail($e);
        } finally {
            $shutdown->run();
        }
    }

    /**
     * After the drain, every journal row must be terminal (COMPLETED/FAILED)
     * or recoverable by a restart (READY/PROCESSING/DELAYED). The journal is
     * append-only, so anything else means the platform stopped tracking a
     * job - reported, and turned into the exit code.
     */
    private function verifyNoJobLost(string $logPath): bool
    {
        $rows = new QueueJournal($logPath)->rows();

        $terminal = 0;
        $recoverable = 0;
        $lost = 0;

        foreach ($rows as $row) {
            match ($row['state']) {
                'COMPLETED', 'FAILED' => $terminal++,
                'READY', 'PROCESSING', 'DELAYED' => $recoverable++,
                default => $lost++,
            };
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
     * The worker-lifecycle observer for queue:consume's forwarder pool: pid,
     * state and current job per worker, written as the JSON snapshot that
     * `workers:status` and `GET /workers` read.
     *
     * @param array<string, mixed> $workersConfig
     */
    private function workerRegistry(WorkerPool $pool, string $logPath, array $workersConfig): WorkerRegistry
    {
        $this->ensureDirectory($workersConfig['data_dir'], 'worker data directory');

        return new WorkerRegistry(
            pool: $pool,
            journal: new QueueJournal($logPath),
            statusPath: $workersConfig['data_dir'] . '/workers.status.json',
        );
    }

    /**
     * The queue counters derived from the journal - the same shape as
     * GET /queue/status.
     */
    private function queueStatus(): int
    {
        $logPath = $this->queueLogPath($this->config());
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
     * One job's metadata straight off the journal. started/completed/error
     * describe the most recent delivery only: the component keeps the latest
     * attempt, not a history of every one.
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

        $logPath = $this->queueLogPath($this->config());
        $row = new QueueJournal($logPath)->rows()[$id] ?? null;

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

    /**
     * The consumer's worker lifecycle from the snapshot it keeps writing -
     * the same data `GET /workers` answers with.
     */
    private function workersStatus(): int
    {
        $path = $this->workersStatusPath($this->config());

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

    // ---------------------------------------------------------------------
    // Benchmarks
    // ---------------------------------------------------------------------

    /**
     * Publish N READY jobs, run them through the real queue -> consumer ->
     * pool -> job path on a fixed-size pool, and report time, throughput,
     * latencies and utilization. Compare e.g. 1000/4 with 1000/8 to see that
     * doubling the workers does not halve the time.
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

        $metrics = $this->measureQueue($jobs, $workers);

        if ($metrics === null) {
            return 1;
        }

        printf("Queue benchmark: %d jobs, %d pool workers\n", $metrics['jobs'], $metrics['workers']);
        printf("  total processing time  %.4fs\n", $metrics['wall_seconds']);
        printf("  throughput             %.1f jobs/s\n", $metrics['throughput_per_sec']);
        printf("  average latency        %.2f ms\n", $metrics['avg_latency_ms']);
        printf("  p95 latency            %.2f ms\n", $metrics['p95_latency_ms']);
        printf("  queue depth            %d\n", $metrics['queue_depth']);
        printf("  worker utilization     %.1f%%\n", $metrics['worker_utilization'] * 100);

        return 0;
    }

    /**
     * The load tests (Step 29) as one run with one report. The command owns
     * the flags and the baseline; LoadTestRunner owns the measurement, and
     * its queue phases call measureQueue() - the `benchmark` workload itself,
     * not a lookalike.
     *
     * @param list<string> $args
     */
    private function loadTests(array $args): int
    {
        $options = ['requests' => 1000, 'concurrency' => 8, 'jobs' => 1000, 'workers' => '1,2,4,8', 'json' => false];

        foreach ($args as $arg) {
            if ($arg === '--json') {
                $options['json'] = true;

                continue;
            }

            if (!preg_match('/^--(requests|concurrency|jobs|workers)=(.+)$/', $arg, $matches)) {
                fwrite(STDERR, sprintf("Unknown option \"%s\".\n", $arg));
                $this->loadUsage();

                return 1;
            }

            $options[$matches[1]] = $matches[2];
        }

        $workerCounts = array_values(array_filter(array_map(
            static fn (string $count): int => (int) trim($count),
            explode(',', (string) $options['workers']),
        ), static fn (int $count): bool => $count > 0));

        if ($workerCounts === []) {
            fwrite(STDERR, "--workers needs at least one pool size, for example --workers=1,2,4,8.\n");

            return 1;
        }

        $runner = new LoadTestRunner(
            queueBenchmark: fn (int $jobs, int $workers): ?array => $this->measureQueue($jobs, $workers),
            requests: (int) $options['requests'],
            concurrency: (int) $options['concurrency'],
            jobs: (int) $options['jobs'],
            baselineWorkers: (int) $this->config()['workers']['count'],
            workerCounts: $workerCounts,
        );

        try {
            $report = $runner->run();
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        echo $options['json'] ? $report->toJson() . PHP_EOL : $report->toText();

        return 0;
    }

    private function loadUsage(): void
    {
        fwrite(STDERR, <<<TXT
            Usage: platform.php load [options]

              --requests=N      read requests per HTTP phase, and read orders
                                seeded for them (default 1000, max 20000)
              --concurrency=N   in-flight HTTP requests, 1-64 (default 8)
              --jobs=N          queue jobs per worker-scaling run (default 1000)
              --workers=LIST    pool sizes to compare, comma separated
                                (default 1,2,4,8)
              --json            the report as JSON instead of a table

            The run owns the platform: it refuses to start if a serve is
            already listening, and it stops everything it started.

            TXT);
    }

    /**
     * One run of the queue workload, measured and returned instead of
     * printed, so `benchmark` and every worker count of `load` share one
     * implementation.
     *
     * @return array<string, mixed>|null the metrics, or null (error already
     *                                   on STDERR) when the run failed
     */
    private function measureQueue(int $jobs, int $workers): ?array
    {
        $config = $this->config();
        $shutdown = new ShutdownStack('queue benchmark');

        try {
            $this->openDatabase($shutdown, $config['database']);
            $this->ensureCacheServerIfEnabled($shutdown, $config['cache']);

            $benchDir = sys_get_temp_dir() . '/php-systems-platform/bench-' . uniqid('', true);
            $this->ensureDirectory($benchDir, 'benchmark directory');

            $socketPath = sys_get_temp_dir() . '/php-bench-' . uniqid('', true) . '.sock';

            // Thousands of jobs through a small pool: the default 5s task
            // timeout would fail jobs queued behind a burst, so the pool gets
            // the full 30s the forwarders wait.
            $master = $this->spawnIsolatedPool($benchDir, $socketPath, $workers, 30, 'Could not start the benchmark worker pool.');
            $shutdown->push(fn (): null => $this->terminate($master), 'benchmark pool');

            if (!$this->waitForSocket($socketPath)) {
                throw new RuntimeException(sprintf('Benchmark pool did not start listening on "%s" in time.', $socketPath));
            }

            return new QueueBenchmark(logPath: $benchDir . '/queue.log', socketPath: $socketPath, forwarders: $workers)
                ->run($jobs, $workers);
        } catch (RuntimeException $e) {
            $this->fail($e);

            return null;
        } finally {
            $shutdown->run();
        }
    }

    /**
     * The same order snapshot loaded by every execution model - sequential,
     * forked (one process per part), pooled (workers that already exist).
     *
     * Two passes, deliberately. Local catalog rows alone make a round trip
     * per part cost more than the overlap saves ("do not add concurrency
     * merely because it is possible"); a simulated external dependency per
     * part is the case the fan-out exists for.
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
        $shutdown = new ShutdownStack('order load comparison');

        try {
            $database = $this->openDatabase($shutdown, $databaseConfig);
            $this->ensureWorkerPool($shutdown, $workersConfig);

            $orders = new OrderService(new OrderRepository($database));
            $catalog = new CatalogRepository($database);
            // A part sleeping on its simulated dependency must not look like a
            // task timeout.
            $runner = new ConcurrentTaskRunner(
                $this->poolClient($workersConfig, max((float) $workersConfig['task_timeout'], $delayMs / 1000 + 5.0)),
            );

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

            return 0;
        } catch (RuntimeException $e) {
            return $this->fail($e);
        } finally {
            $shutdown->run();
        }
    }

    /**
     * One line per execution model; the first is the baseline.
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

    // ---------------------------------------------------------------------
    // Memory
    // ---------------------------------------------------------------------

    /**
     * Memory of 1, 2, 4 and 8 workers before and after each writes its own
     * copy of the same data. Every count gets a freshly forked pool rather
     * than one resized pool, so "8 workers" never means 8 that inherited
     * earlier traffic.
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
     * One isolated pool of $workers processes, measured and torn down before
     * returning - or null (error already on STDERR) if any step failed.
     *
     * @return array{workers: int, workers_observed: int, parent_rss: ?int, before_avg_rss: ?int, before_min_rss: ?int, before_max_rss: ?int, after_avg_rss: ?int, after_min_rss: ?int, after_max_rss: ?int, total_before_rss: ?int, total_after_rss: ?int}|null
     */
    private function runWorkerMemoryRound(WorkerMemoryBenchmark $benchmark, int $workers, int $elements): ?array
    {
        $pool = null;

        try {
            $dir = sys_get_temp_dir() . '/php-systems-platform/memdemo-' . uniqid('', true);
            $this->ensureDirectory($dir);

            $socketPath = sys_get_temp_dir() . '/php-memdemo-' . uniqid('', true) . '.sock';
            $pool = $this->spawnIsolatedPool($dir, $socketPath, $workers, 30, sprintf('Could not start a %d-worker pool.', $workers));

            if (!$this->waitForSocket($socketPath)) {
                throw new RuntimeException(sprintf('The %d-worker pool did not start listening in time.', $workers));
            }

            $masterPid = (int) proc_get_status($pool)['pid'];

            return $benchmark->run(new ConcurrentTaskRunner(new WorkerPoolClient($socketPath, 30.0)), $workers, $elements, $masterPid);
        } catch (RuntimeException $e) {
            $this->fail($e);

            return null;
        } finally {
            // finally, not after the catch: any throwable must still stop a
            // Master that would otherwise outlive this command.
            if ($pool !== null) {
                $this->terminate($pool);
            }
        }
    }

    /**
     * Fork a child over a shared array and print its memory in three stages:
     * before the fork, right after it (pages still shared), and after the
     * child writes (copy-on-write has run). Needs no platform infrastructure.
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
     * One line: the snapshot's PHP and OS memory, each with its signed delta
     * from $previous when one is given.
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
        $value = $this->formatMegabytes($bytes);

        if ($bytes === null || $previous === null) {
            return $value;
        }

        $delta = $bytes - $previous;

        return sprintf('%s (%s%.1fM)', $value, $delta >= 0 ? '+' : '', $delta / 1_048_576);
    }

    private function formatMegabytes(?int $bytes): string
    {
        return $bytes === null ? 'n/a' : sprintf('%.1fM', $bytes / 1_048_576);
    }

    // ---------------------------------------------------------------------
    // Delivery and failure demos
    // ---------------------------------------------------------------------

    /**
     * Two orders, delivered twice each. Without a key or guard the second
     * delivery settles the order again (one more unit of stock gone); under
     * the idempotency guard the second delivery - a fresh executor, the way a
     * restarted worker sees the store - reads the key back and skips.
     *
     * Needs only the database server; a dead cache counts as a bypass, the
     * same tolerance the jobs themselves have.
     */
    private function idempotencyDemo(): int
    {
        printf("At-least-once vs exactly-once\n\n");

        $config = $this->config();
        $databaseConfig = $config['database'];
        $cacheConfig = $config['cache'];
        $shutdown = new ShutdownStack('idempotency demo');

        try {
            $database = $this->openDatabase($shutdown, $databaseConfig);

            $orders = new OrderService(new OrderRepository($database));
            $catalog = new CatalogRepository($database);
            $clock = new SystemClock();
            $producer = new Producer(new InMemoryQueue($clock), new JobFactory($clock, new MetricsCollector()));
            $stockOf = static fn (string $sku): int => (int) ($catalog->findStock($sku)->available ?? 0);
            $deliver = static fn (JobExecutor $executor, string $orderId, ?string $key = null): mixed => $executor(
                $producer->dispatch(OrderProcessJob::TYPE, ['order_id' => $orderId], maxAttempts: 3, idempotencyKey: $key),
            );

            printf("Scenario: an order completes, and settling takes one unit of stock.\n");
            printf("The settle must happen exactly once; a redelivery must not settle again.\n\n");

            $first = $orders->createOrder('Idempotency Demo', 19.99);

            printf("1. Without a guard (no key):\n");
            printf("   order    %s\n", $first->id);
            printf("   status   completed, one unit settled\n");
            $before = $stockOf($first->product);

            // The second call is the redelivered copy a restart would send.
            $unguarded = new JobExecutor($databaseConfig, $cacheConfig);
            $deliver($unguarded, $first->id);
            $afterFirst = $stockOf($first->product);
            $deliver($unguarded, $first->id);
            $afterSecond = $stockOf($first->product);

            printf("   stock    %d -> %d -> %d   (each delivery took a unit)\n", $before, $afterFirst, $afterSecond);
            printf("   => a second delivery of the same operation changed the state again.\n\n");

            $second = $orders->createOrder('Idempotency Demo', 19.99);
            $key = sprintf('order.process:%s', $second->id);

            printf("2. With an idempotency key and the guard:\n");
            printf("   order    %s\n", $second->id);
            printf("   key      %s\n", $key);

            $store = sprintf('%s/php-systems-platform-idem-demo-%s.log', sys_get_temp_dir(), uniqid('', true));
            $before = $stockOf($second->product);

            // Two executors over one store: the redelivery is served by a
            // fresh worker that only knows what the first one recorded.
            $deliver(new JobExecutor($databaseConfig, $cacheConfig, $store), $second->id, $key);
            $afterFirst = $stockOf($second->product);
            $deliver(new JobExecutor($databaseConfig, $cacheConfig, $store), $second->id, $key);
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
        } catch (RuntimeException $e) {
            return $this->fail($e);
        } finally {
            $shutdown->run();
        }
    }

    /**
     * The two failure sequences, reproduced end to end:
     *
     *     worker crashes -> manager detects -> worker removed -> replacement started
     *     job fails -> retry -> failure -> dead/failed state
     *
     * Both run on throwaway resources (their own pool socket, a fresh
     * journal), so nothing already running is disturbed. Refused outside
     * development/demo environments: production does not kill workers on
     * demand.
     */
    private function failureDemo(): int
    {
        if (!$this->config()['failure_injection']['enabled']) {
            fwrite(STDERR, "Failure injection is disabled (PLATFORM_ENV is not a development/demo environment).\n");
            fwrite(STDERR, "Run it armed, e.g. PLATFORM_ENV=dev php bin/platform.php failure:demo\n");

            return 1;
        }

        printf("Failure injection (development mode)\n\n");

        $crashExit = $this->failureDemoWorkerCrash();
        $jobExit = $this->failureDemoFailingJob();

        return $crashExit === 0 && $jobExit === 0 ? 0 : 1;
    }

    /** The worker-crash sequence, against a two-worker pool spawned for it. */
    private function failureDemoWorkerCrash(): int
    {
        $pool = null;

        try {
            $dir = sys_get_temp_dir() . '/php-systems-platform/failuredemo-' . uniqid('', true);
            $this->ensureDirectory($dir);

            $socketPath = sys_get_temp_dir() . '/php-failuredemo-' . uniqid('', true) . '.sock';
            $pool = $this->spawnIsolatedPool($dir, $socketPath, 2, 5, 'Could not start the demo worker pool.');

            if (!$this->waitForSocket($socketPath)) {
                throw new RuntimeException('The demo worker pool did not start listening in time.');
            }

            $report = new WorkerFailureInjector(new WorkerPoolClient($socketPath, 5.0))->crashOneWorker();

            printf("1. A worker crashes.\n");
            printf("   worker crashes        worker.crash SIGKILLed pid %s mid-request\n", $report['crashed_pid'] === null ? '?' : (string) $report['crashed_pid']);
            printf("   manager detects       %s (%s, %.1f ms)\n", $report['crash_detected'] ? 'yes' : 'no', $report['error'], $report['detected_ms']);
            printf("   worker removed        %s (%.1f ms after the crash)\n", $report['worker_removed'] ? 'yes' : 'no', $report['removed_ms'] ?? 0.0);
            printf("   replacement started   %s (new pid %s, %.1f ms after the crash)\n", $report['replacement_started'] ? 'yes' : 'no', $report['replacement_pid'] ?? '?', $report['replaced_ms'] ?? 0.0);
            printf("   pool size             %d workers (back to configured)\n\n", $report['pool_size']);

            return $report['crash_detected'] && $report['worker_removed'] && $report['replacement_started'] ? 0 : 1;
        } catch (RuntimeException $e) {
            return $this->fail($e);
        } finally {
            if ($pool !== null) {
                $this->terminate($pool);
            }
        }
    }

    /**
     * The job-failure sequence: publish demo.failing onto a fresh journal and
     * run the real dispatcher machinery until the journal shows it dead. The
     * database is needed only because JobExecutor connects eagerly - the
     * failing job itself never touches it.
     */
    private function failureDemoFailingJob(): int
    {
        $config = $this->config();
        $shutdown = new ShutdownStack('failing job demo');

        try {
            $this->openDatabase($shutdown, $config['database']);
            $this->ensureCacheServerIfEnabled($shutdown, $config['cache']);

            $dir = sys_get_temp_dir() . '/php-systems-platform';
            $this->ensureDirectory($dir);
            $logPath = $dir . '/failingjob-' . uniqid('', true) . '.log';
            $clock = new SystemClock();

            $job = new Producer(
                new InMemoryQueue($clock, new FileStorage($logPath)),
                new JobFactory($clock, new MetricsCollector()),
            )->dispatch(FailingJob::TYPE, [], maxAttempts: (int) $config['queue']['max_attempts']);
            $jobId = (string) $job->getId();

            $executor = new JobExecutor($config['database'], $config['cache']);
            $pool = new WorkerPool(size: 2, handler: static fn (Job $job): mixed => $executor($job));
            $dispatcher = new JobDispatcher(
                queue: InMemoryQueue::restoreFromStorage(new FileStorage($logPath), $clock),
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
            printf("   published demo.failing (%s, max %d attempts)\n", $jobId, $job->getMaxAttempts());

            $journal = new QueueJournal($logPath);
            $lastAttempts = 0;

            // dispatchNext() is false whenever nothing can move right now -
            // including the retry delay a just-failed job sits out. So this
            // polls until the journal shows a terminal state; a plain
            // while (dispatchNext()) would stop after the first failure.
            $deadline = microtime(true) + 30.0;

            while (microtime(true) < $deadline) {
                if ($dispatcher->dispatchNext()) {
                    $attempts = (int) ($journal->rows()[$jobId]['attempts'] ?? 0);

                    if ($attempts !== $lastAttempts) {
                        printf("   attempt %d -> failed\n", $attempts);
                        $lastAttempts = $attempts;
                    }

                    continue;
                }

                $state = (string) ($journal->rows()[$jobId]['state'] ?? '');

                if ($state === 'FAILED' || $state === 'COMPLETED') {
                    break;
                }

                usleep(10_000);
            }

            $pool->shutdown();

            $row = $journal->rows()[$jobId] ?? [];
            $snapshot = $journal->snapshot();
            $state = (string) ($row['state'] ?? '?');
            $attempts = (int) ($row['attempts'] ?? 0);

            printf("   all %d attempts failed -> %s (dead state)\n", $attempts, $state);
            printf("   last error            %s\n", (string) ($row['lastError'] ?? '-'));
            printf("   journal counters      failed=%d retried=%d depth=%d\n", $snapshot['failed'], $snapshot['retried'], $snapshot['depth']);
            printf(
                "   => retry worked: a well-formed but hopeless job spent its budget\n"
                . "      and was retired into the FAILED state instead of retried forever.\n",
            );

            return $state === 'FAILED' && $attempts === $job->getMaxAttempts() ? 0 : 1;
        } catch (RuntimeException $e) {
            return $this->fail($e);
        } finally {
            $shutdown->run();
        }
    }

    // ---------------------------------------------------------------------
    // Observability
    // ---------------------------------------------------------------------

    /**
     * The CLI mirror of GET /metrics, over the same MetricsReporter. Outside a
     * serve the registry is empty, so this shows the pull side: queue
     * journal, the live pool (omitted when it does not answer) and this
     * process's RSS.
     */
    private function metricsCommand(): int
    {
        $config = $this->config();

        $reporter = new MetricsReporter(
            new MetricsRegistry(),
            new QueueJournal($this->queueLogPath($config)),
            $this->poolClient($config['workers']),
            new MemoryReporter(),
        );

        $lines = [];

        foreach ($reporter->snapshot() as $name => $value) {
            $lines[] = sprintf('%s %s', $name, is_float($value) ? sprintf('%.4f', $value) : $value);
        }

        echo implode(PHP_EOL, $lines) . PHP_EOL;

        return 0;
    }

    /**
     * Every span recorded for one request_id, from the JSONL journal serve and
     * every pool worker write into: which request created the job, which
     * worker ran it, how long it took, how many retries it burned.
     *
     * @param list<string> $args
     */
    private function traceCommand(array $args): int
    {
        $requestId = $args[0] ?? '';

        if ($requestId === '') {
            fwrite(STDERR, "usage: php bin/platform.php trace <request_id>\n");

            return 1;
        }

        $spans = new Trace($this->traceStorePath($this->config()))->readLog($requestId);

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
                "%-13s %7.4fs%s%s %s\n",
                (string) $span['operation'],
                (float) $span['duration'],
                $detail,
                $fields,
                (string) $span['request_id'],
            );
        }

        return 0;
    }

    /**
     * `jobs.trace_store` when the config declares one; null (tracing off)
     * otherwise.
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
     * Every component's state and headline numbers, laid out as PLAN.md's
     * example prints it. Three kinds of source, each optional:
     *
     *   a running serve's /metrics  http/cache/db counters and the master's
     *                               RSS exist only inside serve
     *   live probes                 a TCP connect per server, one stats call
     *                               to the pool
     *   the queue journal           queue.* counts, as queue:status reads them
     *
     * None failing is an error - a stopped platform is what this command is
     * for. Serve-only counters print as 0 (RSS as n/a) when no serve answers.
     */
    private function statusCommand(): int
    {
        $config = $this->config();
        $databaseConfig = $config['database'];
        $cacheConfig = $config['cache'];

        $metrics = $this->statusMetrics($config['http']);
        $databaseRunning = $this->waitForPort((string) $databaseConfig['host'], (int) $databaseConfig['port'], 0.3);
        $cacheRunning = $this->waitForPort((string) $cacheConfig['host'], (int) $cacheConfig['port'], 0.3);
        [$poolRunning, $workers] = $this->statusWorkerStats($config['workers']);
        $queue = new QueueJournal($this->queueLogPath($config))->snapshot();

        $count = fn (string $metric): string => $this->formatStatusCount($this->statusMetricInt($metrics, $metric));
        $running = static fn (bool $up): string => $up ? 'running' : 'stopped';

        printf("PHP Systems Platform\n--------------------\n\n");

        $this->printStatusSection('HTTP Server', [
            'status' => $running($metrics !== null),
            'requests' => $count('http.requests'),
            'errors' => $count('http.errors'),
        ]);

        $this->printStatusSection('Cache', [
            'status' => $running($cacheRunning),
            'hits' => $count('cache.hit'),
            'misses' => $count('cache.miss'),
        ]);

        $this->printStatusSection('Database', [
            'status' => $running($databaseRunning),
            'operations' => $count('db.operations'),
        ]);

        $this->printStatusSection('Queue', [
            'status' => $running($poolRunning),
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

        // master = the serve process's own RSS; workers = the pool average,
        // from /metrics or, without a serve, from the same stats call.
        $this->printStatusSection('Memory', [
            'master RSS' => $this->formatMegabytes($this->statusMetricInt($metrics, 'process.rss')),
            'workers RSS' => $this->formatMegabytes($this->statusMetricInt($metrics, 'worker.rss') ?? $workers['rss']),
        ]);

        return 0;
    }

    /**
     * A heading, then one row per fact with labels padded to one column.
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

    /** Thousands separators; a counter only a stopped serve could hold reads as 0. */
    private function formatStatusCount(?int $value): string
    {
        return number_format($value ?? 0);
    }

    /**
     * @param array<string, string>|null $metrics
     */
    private function statusMetricInt(?array $metrics, string $name): ?int
    {
        return $metrics !== null && isset($metrics[$name]) ? (int) $metrics[$name] : null;
    }

    /**
     * A running serve's GET /metrics, read with one raw HTTP request.
     * "Connection: close" makes read-until-EOF a complete answer; everything
     * after the blank line is the metric dump. Null when no serve answers or
     * the answer holds no metric lines.
     *
     * @param array<string, mixed> $http
     *
     * @return array<string, string>|null metric name -> value, as printed
     */
    private function statusMetrics(array $http): ?array
    {
        $host = (string) $http['host'];
        $socket = @stream_socket_client(sprintf('tcp://%s:%d', $host, (int) $http['port']), $errorCode, $errorMessage, 0.5);

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
     * The pool's live worker tally and whether a Master answered at all. The
     * aggregation mirrors MetricsReporter's so the two agree, but it is read
     * directly: a pool running without a serve still answers.
     *
     * @param array<string, mixed> $config
     *
     * @return array{0: bool, 1: array{active: int, busy: int, idle: int, failed: int, rss: int|null}}
     */
    private function statusWorkerStats(array $config): array
    {
        $client = $this->poolClient($config);

        try {
            $stats = $client->stats();
        } catch (\Throwable) {
            return [false, ['active' => 0, 'busy' => 0, 'idle' => 0, 'failed' => 0, 'rss' => null]];
        }

        $client->close();

        $tally = ['active' => 0, 'busy' => 0, 'idle' => 0, 'failed' => 0];
        $samples = [];

        foreach ($stats as $worker) {
            $state = (string) $worker['state'];

            if ($state === 'DEAD') {
                $tally['failed']++;

                continue;
            }

            $tally['active']++;

            if ($state === 'BUSY') {
                $tally['busy']++;
            } elseif ($state === 'IDLE') {
                $tally['idle']++;
            }

            if ($worker['memoryBytes'] !== null) {
                $samples[] = (int) $worker['memoryBytes'];
            }
        }

        $tally['rss'] = $samples === [] ? null : (int) round(array_sum($samples) / count($samples));

        return [true, $tally];
    }

    // ---------------------------------------------------------------------
    // Small shared helpers
    // ---------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        return require __DIR__ . '/../../config/platform.php';
    }

    /** @param array<string, mixed> $config */
    private function queueLogPath(array $config): string
    {
        return $config['queue']['data_dir'] . '/queue.log';
    }

    /** @param array<string, mixed> $config */
    private function workersStatusPath(array $config): string
    {
        return $config['workers']['data_dir'] . '/workers.status.json';
    }

    /**
     * A new client (and so a new connection) to the configured pool.
     *
     * @param array<string, mixed> $workersConfig
     */
    private function poolClient(array $workersConfig, ?float $timeoutSeconds = null): WorkerPoolClient
    {
        return new WorkerPoolClient(
            (string) $workersConfig['socket'],
            $timeoutSeconds ?? (float) $workersConfig['task_timeout'],
        );
    }

    private function binary(string $name): string
    {
        return dirname(__DIR__, 2) . '/bin/' . $name;
    }

    /** Create $dir with its parents unless it exists; $what names it in the error. */
    private function ensureDirectory(string $dir, string $what = ''): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0o777, true) && !is_dir($dir)) {
            throw new RuntimeException(sprintf('Could not create %s"%s".', $what === '' ? '' : $what . ' ', $dir));
        }
    }

    private function fail(RuntimeException $e): int
    {
        fwrite(STDERR, $e->getMessage() . PHP_EOL);

        return 1;
    }

    private function formatTimestamp(float $unixTime): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', (int) $unixTime);
    }
}
