<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Cli;

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
use PhpSystemsPlatform\Http\Router;

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
        $http = $this->config()['http'];

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

            return 1;
        }

        $parser = new HttpParser($serverConfig->maxHeaderBytes, $serverConfig->maxBodyBytes);
        $encoder = new ResponseEncoder();
        $logger = new StderrLogger();
        $metrics = new ServerMetrics();
        $loop = new SelectLoop();

        $application = $this->application();

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
        printf("Shutdown complete.\n");

        return 0;
    }

    /**
     * The platform's routes, in their Application. Serves as the wiring note
     * for the platform as well: this is where the health endpoint lands in
     * Phase 3 and where the order routes join in Phase 4.
     */
    private function application(): Application
    {
        $router = new Router();
        $router->get('/health', (new HealthHandler())(...));

        return new Application($router);
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
