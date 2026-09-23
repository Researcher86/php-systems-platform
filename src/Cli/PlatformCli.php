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
use PhpMiniDatabase\Client\ClientConfig;
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
use PhpSystemsPlatform\Application\Handlers\HealthHandler;
use PhpSystemsPlatform\Application\Handlers\OrderCreateHandler;
use PhpSystemsPlatform\Application\Handlers\OrderReadHandler;
use PhpSystemsPlatform\Application\Handlers\OrderUpdateHandler;
use PhpSystemsPlatform\Application\Handlers\ParallelHandler;
use PhpSystemsPlatform\Application\Handlers\QueueStatusHandler;
use PhpSystemsPlatform\Application\Handlers\WorkersStatusHandler;
use PhpSystemsPlatform\Cache\CacheService;
use PhpSystemsPlatform\Domain\OrderService;
use PhpSystemsPlatform\Http\Router;
use PhpSystemsPlatform\Queue\QueueConsumer;
use PhpSystemsPlatform\Queue\QueueJournal;
use PhpSystemsPlatform\Storage\Database;
use PhpSystemsPlatform\Storage\Migrator;
use PhpSystemsPlatform\Storage\Repositories\OrderRepository;
use PhpSystemsPlatform\Workers\ConcurrentTaskRunner;
use PhpSystemsPlatform\Workers\QueueBenchmark;
use PhpSystemsPlatform\Workers\WorkerManager;
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
        'queue:publish' => 'Publish sample jobs into the queue.',
        'queue:consume' => 'Run the queue consumer (pairs with worker pool).',
        'queue:status' => 'Show queue depth and job counters.',
        'workers:status' => 'Show the queue consumer worker lifecycle.',
        'status' => 'Show the state of every platform component.',
        'demo' => 'Run the complete end-to-end platform story.',
        'benchmark' => 'Run the queue benchmark: benchmark <jobs> <workers>.',
        'memory:demo' => 'Demonstrate fork() and copy-on-write memory behavior.',
        'workers:memory' => 'Measure worker process memory (1, 2, 4, 8 workers).',
        'failure:demo' => 'Reproduce the failure scenarios end to end.',
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
            'queue:publish' => $this->queuePublish(array_slice($argv, 2)),
            'queue:consume' => $this->queueConsume(),
            'queue:status' => $this->queueStatus(),
            'workers:status' => $this->workersStatus(),
            'benchmark' => $this->queueBenchmark(array_slice($argv, 2)),

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

        $database = Database::fromConfig(new ClientConfig(
            host: $databaseConfig['host'],
            port: $databaseConfig['port'],
            connectTimeoutSeconds: $databaseConfig['timeout'],
        ));

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

        $cache = CacheService::fromConfig($cacheConfig);

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
            // Short idle and header timeouts for a dev server: a connection
            // that goes quiet is reclaimed by the periodic sweep instead of
            // holding a socket forever.
            connectionTimeout: 5.0,
            headerTimeout: 5.0,
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

        $application = $this->application($database, $cache, $producer, $runner, $config['queue']['data_dir'] . '/queue.log', $config['workers']['data_dir'] . '/workers.status.json');

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
     * forwarder snapshot.
     */
    private function application(Database $database, CacheService $cache, ?Producer $producer = null, ?ConcurrentTaskRunner $runner = null, string $queueLogPath = '', string $workersStatusPath = ''): Application
    {
        $orders = new OrderService(new OrderRepository($database), $producer);

        $router = new Router();
        $router->get('/health', (new HealthHandler())(...));
        $router->post('/orders', (new OrderCreateHandler($orders, $cache))(...));
        $router->get('/orders/{id}', (new OrderReadHandler($orders, $cache))(...));
        $router->put('/orders/{id}', (new OrderUpdateHandler($orders, $cache))(...));
        $router->get('/parallel', (new ParallelHandler($runner))(...));
        $router->get('/queue/status', (new QueueStatusHandler($queueLogPath))(...));
        $router->get('/workers', (new WorkersStatusHandler($workersStatusPath))(...));

        return new Application($router);
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
     * HTTP.
     *
     * @param list<string> $args
     */
    private function queuePublish(array $args): int
    {
        $type = $args[0] ?? null;

        if ($type === null) {
            fwrite(STDERR, "Usage: php bin/platform.php queue:publish <type> [payload-json]\n");

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

        $config = $this->config();

        try {
            $producer = $this->producer($config['queue']);
        } catch (RuntimeException $e) {
            fwrite(STDERR, $e->getMessage() . PHP_EOL);

            return 1;
        }

        $job = $producer->dispatch($type, $payload, maxAttempts: (int) $config['queue']['max_attempts']);

        printf(
            "Published job %s (type=%s, %d max attempts) to %s\n",
            $job->getId(),
            $type,
            $job->getMaxAttempts(),
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
     */
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

        $database = Database::fromConfig(new ClientConfig(
            host: $databaseConfig['host'],
            port: $databaseConfig['port'],
            connectTimeoutSeconds: $databaseConfig['timeout'],
        ));

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

        printf("Consumer stopped.\n");

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

        $database->close();
        $this->stopWorkerPoolIfOwned($ownsWorkerPool);
        $this->stopCacheServerIfOwned($ownsCacheServer);
        $this->stopDatabaseServerIfOwned($ownsDatabaseServer, $databaseConfig);

        return 0;
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

        $database = Database::fromConfig(new ClientConfig(
            host: $databaseConfig['host'],
            port: $databaseConfig['port'],
            connectTimeoutSeconds: $databaseConfig['timeout'],
        ));

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
