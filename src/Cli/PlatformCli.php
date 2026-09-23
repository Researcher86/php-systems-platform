<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Cli;

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
use PhpSystemsPlatform\Cache\CacheService;
use PhpSystemsPlatform\Domain\OrderService;
use PhpSystemsPlatform\Http\Router;
use PhpSystemsPlatform\Storage\Database;
use PhpSystemsPlatform\Storage\Migrator;
use PhpSystemsPlatform\Storage\Repositories\OrderRepository;
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
        'status' => 'Show the state of every platform component.',
        'demo' => 'Run the complete end-to-end platform story.',
        'benchmark' => 'Run the platform benchmark suite.',
        'memory:demo' => 'Demonstrate fork() and copy-on-write memory behavior.',
        'workers:memory' => 'Measure worker process memory (1, 2, 4, 8 workers).',
        'failure:demo' => 'Reproduce the failure scenarios end to end.',
    ];

    /**
     * The cache server this serve spawned, when it spawned one. The database
     * server is tracked by pid file; the cache has no daemon mode, so its
     * process is owned directly and must not outlive the HTTP process.
     *
     * @var resource|null
     */
    private mixed $cacheProcess = null;

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
            fwrite(STDERR, "Run 'php bin/platform help' for the command list.\n");

            return 1;
        }

        return $this->dispatch($command);
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
        fwrite(STDOUT, "  php bin/platform <command>\n");

        return 0;
    }

    private function dispatch(string $command): int
    {
        return match ($command) {
            'serve' => $this->serve(),
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

        $http = $config['http'];

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

        $application = $this->application($database, $cache);

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
        $this->stopCacheServerIfOwned($ownsCacheServer);
        $this->stopDatabaseServerIfOwned($ownsDatabaseServer, $databaseConfig);
        printf("Shutdown complete.\n");

        return 0;
    }

    /**
     * The platform's routes, in their Application. Serves as the wiring note
     * for the platform as well: the health endpoint landed in Phase 3, the
     * order endpoints in Phase 4 on top of the injected Database, and the
     * cache-first read path in Phase 5 on top of the injected CacheService.
     */
    private function application(Database $database, CacheService $cache): Application
    {
        $orders = new OrderService(new OrderRepository($database));

        $router = new Router();
        $router->get('/health', (new HealthHandler())(...));
        $router->post('/orders', (new OrderCreateHandler($orders))(...));
        $router->get('/orders/{id}', (new OrderReadHandler($orders, $cache))(...));
        $router->put('/orders/{id}', (new OrderUpdateHandler($orders))(...));

        return new Application($router);
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

        if ($hadServer) {
            printf("Database server already running (pid %s)\n", trim($output));

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
        return dirname(__DIR__, 2) . '/bin/minidb';
    }

    private function cacheServerBinary(): string
    {
        return dirname(__DIR__, 2) . '/bin/cache';
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
