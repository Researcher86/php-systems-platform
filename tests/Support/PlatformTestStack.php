<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Tests\Support;

use PhpJobQueue\Metrics\MetricsCollector;
use PhpJobQueue\Persistence\FileStorage;
use PhpJobQueue\Producer\JobFactory;
use PhpJobQueue\Producer\Producer;
use PhpJobQueue\Queue\InMemoryQueue;
use PhpJobQueue\Support\SystemClock;
use PhpSystemsPlatform\Cache\CacheService;
use PhpSystemsPlatform\Queue\QueueJournal;
use PhpSystemsPlatform\Storage\Database;
use PhpWorkerPool\Sdk\WorkerPoolClient;
use RuntimeException;

/**
 * The one place the integration suites get a running platform from.
 *
 * PLAN Step 27 asks for integration tests at every boundary, and every
 * boundary of this platform is a real component reached over a real socket.
 * So the harness is not a fixture that fakes those components: it starts the
 * platform's own `php bin/platform.php serve` - the same process an operator
 * starts, with the real ports, the real data directories and the real
 * wiring - and hands out clients for what that serve owns (its database, its
 * cache, its queue journal, its worker pool). A serve that is already
 * answering is reused, and a stack this harness started is stopped again when
 * the last suite releases it, so no suite leaves the ports occupied for the
 * next one.
 *
 * Reference counting, rather than one global boot: the suites that need a
 * platform ask for it in setUpBeforeClass and give it back in
 * tearDownAfterClass, and the stack only shuts down when the last of them is
 * done. Suites that must own the ports themselves (the demo, the benchmarks)
 * are therefore unaffected by the order PHPUnit happens to run things in.
 */
final class PlatformTestStack
{
    private const string HOST = '127.0.0.1';

    private const float SERVE_START_DEADLINE_SECONDS = 30.0;

    private const float CHILD_STOP_DEADLINE_SECONDS = 20.0;

    private static ?self $running = null;

    private static int $holders = 0;

    /** @var resource|null */
    private mixed $serveProcess = null;

    /** @var array<int, resource> the queue consumers this stack started */
    private array $consumers = [];

    private bool $ownsServe = false;

    private Database $database;

    private CacheService $cache;

    /** @var array<string, mixed> */
    private array $config;

    private readonly string $logDir;

    private function __construct()
    {
        $this->config = require dirname(__DIR__, 2) . '/config/platform.php';
        $this->logDir = sys_get_temp_dir() . '/php-systems-platform-stack';

        $this->serveProcess = null;
        $this->startServe();

        $this->database = Database::connect($this->config['database']);
        $this->cache = CacheService::fromConfig($this->config['cache']);
    }

    /**
     * Take a reference to the running platform, starting it if this is the
     * first suite to ask. Every acquire() must be matched by a release().
     */
    public static function acquire(): self
    {
        if (!self::$running instanceof self) {
            self::$running = new self();
        }

        self::$holders++;

        return self::$running;
    }

    /**
     * Give the reference back. The last one out stops the serve this harness
     * started - the same SIGTERM an operator would send, so the shutdown
     * path is the one that runs on the way out too.
     */
    public static function release(): void
    {
        self::$holders--;

        if (self::$holders > 0 || !self::$running instanceof self) {
            return;
        }

        $stack = self::$running;
        self::$running = null;

        $stack->stop();
    }

    /** @return array<string, mixed> */
    public function config(): array
    {
        return $this->config;
    }

    public function httpPort(): int
    {
        return (int) $this->config['http']['port'];
    }

    /** The platform's shared database connection, reached the way the components are. */
    public function database(): Database
    {
        return $this->database;
    }

    /** The platform's cache service, over the same cache server serve uses. */
    public function cache(): CacheService
    {
        return $this->cache;
    }

    /** A cache service of the caller's own, closed by whoever made it. */
    public function newCache(): CacheService
    {
        return CacheService::fromConfig($this->config['cache']);
    }

    public function journal(): QueueJournal
    {
        return new QueueJournal($this->queueLogPath());
    }

    private function queueLogPath(): string
    {
        return $this->config['queue']['data_dir'] . '/queue.log';
    }

    public function pool(): WorkerPoolClient
    {
        $workers = $this->config['workers'];

        return new WorkerPoolClient((string) $workers['socket'], (float) $workers['task_timeout']);
    }

    private function logDir(): string
    {
        return $this->logDir;
    }

    /**
     * Publish a job into the platform's queue from the test process, the way
     * serve's own producer does: the same component Producer writing the same
     * append-only journal a consumer restores from. A job published this way
     * is a real cross-process publish - the same artifact the HTTP write path
     * produces - which is what makes the consumer, failure and retry tests
     * below real rather than a producer and consumer talking in one process.
     *
     * @param array<string, mixed> $payload
     *
     * @return string the published job id
     */
    public function publishJob(string $type, array $payload = [], ?int $maxAttempts = null, ?string $idempotencyKey = null): string
    {
        $clock = new SystemClock();
        $config = (array) $this->config['queue'];

        $job = new Producer(
            new InMemoryQueue($clock, new FileStorage($this->queueLogPath())),
            new JobFactory($clock, new MetricsCollector()),
        )->dispatch(
            $type,
            $payload,
            maxAttempts: $maxAttempts ?? (int) $config['max_attempts'],
            idempotencyKey: $idempotencyKey,
        );

        return $job->getId()->toString();
    }

    /**
     * Speak HTTP to the running serve, the way any client does.
     *
     * @param list<string> $headers extra header lines
     *
     * @return array{int, string, list<string>} status, body, raw response headers
     */
    public function http(string $method, string $path, ?string $body = null, array $headers = []): array
    {
        $options = [
            'http' => [
                'method' => $method,
                'header' => 'Content-Type: application/json' . ($headers === [] ? '' : "\r\n" . implode("\r\n", $headers)),
                'content' => $body,
                'timeout' => 8.0,
                'ignore_errors' => true,
            ],
        ];

        $response = @file_get_contents(
            sprintf('http://%s:%d%s', self::HOST, $this->httpPort(), $path),
            false,
            stream_context_create($options),
        );

        $status = 0;

        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $matches) === 1) {
                $status = (int) $matches[1];
            }
        }

        return [$status, (string) $response, $http_response_header ?? []];
    }

    /**
     * Send hand-written bytes at the server and return everything it wrote
     * back, so a test can look at the response line and the header block
     * themselves instead of trusting a client library to have parsed them.
     */
    public function rawHttp(string $request, float $timeoutSeconds = 5.0): string
    {
        $socket = @stream_socket_client(
            sprintf('tcp://%s:%d', self::HOST, $this->httpPort()),
            $code,
            $message,
            $timeoutSeconds,
        );

        if (!is_resource($socket)) {
            throw new RuntimeException(sprintf(
                'Could not connect to the platform HTTP server: %s',
                $message,
            ));
        }

        stream_set_timeout($socket, (int) $timeoutSeconds);
        fwrite($socket, $request);

        $response = '';

        while (!feof($socket)) {
            $chunk = fread($socket, 8192);

            if ($chunk === false || $chunk === '') {
                break;
            }

            $response .= $chunk;

            if (str_contains($response, "\r\n\r\n")) {
                break;
            }
        }

        fclose($socket);

        return $response;
    }

    /** The value of one response header, case-insensitively. */
    public static function header(?array $headers, string $name): ?string
    {
        foreach ($headers ?? [] as $line) {
            if (stripos($line, $name . ':') === 0) {
                return trim(substr($line, strlen($name) + 1));
            }
        }

        return null;
    }

    /**
     * Start the platform's own queue consumer as a real child process, so a
     * suite can stop it with SIGTERM later and judge the shutdown by its exit
     * code. Returns once the consumer has announced itself, so a job
     * published right after this really is picked up by a running consumer.
     *
     * @return array{0: int, 1: resource} pid and process handle
     */
    public function startConsumer(string $logName = 'consumer'): array
    {
        [$pid, $process] = $this->startChild(
            'queue:consume',
            [dirname(__DIR__, 2) . '/bin/platform.php', 'queue:consume'],
            null,
            $logName,
        );
        $this->consumers[$pid] = $process;

        $deadline = microtime(true) + self::SERVE_START_DEADLINE_SECONDS;

        while (microtime(true) < $deadline) {
            $out = (string) @file_get_contents($this->logDir . '/' . $logName . '.out');

            if (str_contains($out, 'Consumer started')) {
                return [$pid, $process];
            }

            usleep(50_000);
        }

        throw new RuntimeException(sprintf(
            'The queue consumer did not start in time. Output: %s',
            (string) @file_get_contents($this->logDir . '/' . $logName . '.out'),
        ));
    }

    /**
     * SIGTERM a child started here and wait for it: the exit code is how the
     * graceful-shutdown claims are judged, so it is read with an explicit
     * waitpid rather than proc_close(), which reports -1 for a child that was
     * already reaped.
     *
     * @param resource|null $process the handle startChild() returned, if the
     *                               caller kept one
     *
     * @return int exit code, or -1 if the child had to be killed
     */
    public function stopChild(int $pid, $process, float $deadlineSeconds = self::CHILD_STOP_DEADLINE_SECONDS): int
    {
        if (is_resource($process)) {
            proc_terminate($process);
        }

        $deadline = microtime(true) + $deadlineSeconds;
        $reaped = pcntl_waitpid($pid, $status, WNOHANG);

        // Polled, not slept through: the point of this wait is to let the
        // child take its graceful shutdown - a serve that is SIGKILLed here
        // never stops the daemons it started, and the next suite inherits
        // them.
        while ($reaped !== $pid && microtime(true) < $deadline) {
            usleep(50_000);
            $reaped = pcntl_waitpid($pid, $status, WNOHANG);
        }

        if ($reaped !== $pid) {
            if (is_resource($process)) {
                proc_terminate($process, 9);
            }

            $reaped = pcntl_waitpid($pid, $status);
        }

        if (is_resource($process)) {
            proc_close($process);
        }

        return $reaped === $pid ? pcntl_wexitstatus($status) : -1;
    }

    /**
     * Start any of the platform's own child processes on a scratch log pair,
     * the way the demo and the benchmarks do.
     *
     * @param list<string> $args
     *
     * @return array{0: int, 1: resource} pid and process handle
     */
    public function startChild(string $name, array $args, ?string $env = null, string $logName = ''): array
    {
        $logName = $logName !== '' ? $logName : $name;

        if (!is_dir($this->logDir) && !@mkdir($this->logDir, 0o777, true) && !is_dir($this->logDir)) {
            throw new RuntimeException(sprintf('Could not create log directory "%s".', $this->logDir));
        }

        $process = proc_open(
            [PHP_BINARY, ...$args],
            [
                1 => ['file', $this->logDir . '/' . $logName . '.out', 'a'],
                2 => ['file', $this->logDir . '/' . $logName . '.err', 'a'],
            ],
            $pipes,
            null,
            $env,
        );

        if (!is_resource($process)) {
            throw new RuntimeException(sprintf('Could not start the %s process.', $name));
        }

        return [(int) proc_get_status($process)['pid'], $process];
    }

    /** Wait until $probe is true, or fail with $message. */
    public function waitFor(callable $probe, float $deadlineSeconds, string $message): void
    {
        $deadline = microtime(true) + $deadlineSeconds;

        while (microtime(true) < $deadline) {
            if ($probe() === true) {
                return;
            }

            usleep(50_000);
        }

        throw new RuntimeException($message);
    }

    private function startServe(): void
    {
        if ($this->portAnswers($this->httpPort())) {
            $this->ownsServe = false;

            return;
        }

        if (!is_dir($this->logDir) && !@mkdir($this->logDir, 0o777, true) && !is_dir($this->logDir)) {
            throw new RuntimeException(sprintf('Could not create log directory "%s".', $this->logDir));
        }

        [$pid, $process] = $this->startChild('serve', [dirname(__DIR__, 2) . '/bin/platform.php', 'serve'], null, 'serve');

        $this->serveProcess = $process;
        $this->ownsServe = true;

        $deadline = microtime(true) + self::SERVE_START_DEADLINE_SECONDS;

        if (!$this->portAnswers($this->httpPort(), self::SERVE_START_DEADLINE_SECONDS)) {
            $exit = $this->stopChild($pid, $process, 5.0);
            $this->serveProcess = null;
            $this->ownsServe = false;

            throw new RuntimeException(sprintf(
                'The platform serve did not start listening on port %d (exit %d). Output: %s',
                $this->httpPort(),
                $exit,
                (string) @file_get_contents($this->logDir . '/serve.out'),
            ));
        }
    }

    private function stop(): void
    {
        // A suite that forgot to stop its consumer must not leave one running
        // against the next suite's platform.
        foreach ($this->consumers as $pid => $process) {
            $this->stopChild($pid, $process, 10.0);
        }

        $this->consumers = [];

        $this->database->close();
        $this->cache->close();

        // Only a serve this harness started is stopped: one that was already
        // answering belongs to whoever started it.
        if (!$this->ownsServe || !is_resource($this->serveProcess)) {
            return;
        }

        $exit = $this->stopChild((int) proc_get_status($this->serveProcess)['pid'], $this->serveProcess);
        $this->serveProcess = null;

        // A serve that had to be killed left the database, cache and worker
        // pool daemons it started running, and the next suite - or the demo -
        // would inherit them. Refusing to hide that is the point.
        if ($exit !== 0) {
            throw new RuntimeException(sprintf(
                'The platform serve this harness started exited %d on SIGTERM instead of shutting down gracefully.',
                $exit,
            ));
        }
    }

    private function portAnswers(int $port, float $timeoutSeconds = 0.3): bool
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            $socket = @stream_socket_client(
                sprintf('tcp://%s:%d', self::HOST, $port),
                $code,
                $message,
                0.2,
            );

            if ($socket !== false) {
                fclose($socket);

                return true;
            }

            usleep(50_000);
        }

        return false;
    }
}
