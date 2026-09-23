<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Tests\Integration;

use PhpJobQueue\Metrics\MetricsCollector;
use PhpJobQueue\Producer\JobFactory;
use PhpJobQueue\Producer\Producer;
use PhpJobQueue\Queue\InMemoryQueue;
use PhpJobQueue\Support\SystemClock;
use PhpMiniDatabase\Client\ClientConfig;
use PhpSystemsPlatform\Cache\CacheService;
use PhpSystemsPlatform\Domain\OrderService;
use PhpSystemsPlatform\Queue\JobContext;
use PhpSystemsPlatform\Queue\Jobs\OrderCreatedJob;
use PhpSystemsPlatform\Storage\Database;
use PhpSystemsPlatform\Storage\Repositories\OrderRepository;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * Integration tests against the real, running stack. The class starts
 * `php bin/platform.php serve` - the platform's own launch, with the real ports
 * and data directories from config - and a serve that is already up is
 * reused rather than started a second time. The tests speak the same
 * protocol a client does (HTTP to 127.0.0.1:8080), read the real append-only
 * queue journal in the queue data directory, reach the database the way the
 * components do, and, when this class started the serve, tear it down again
 * gracefully.
 */
final class ServeIntegrationTest extends TestCase
{
    private const HOST = '127.0.0.1';
    private const HTTP_PORT = 8080;
    private const DB_PORT = 5433;
    private const CACHE_PORT = 6380;

    private const DATA_DIR = '/tmp/php-systems-platform';
    private const LOG_DIR = '/tmp/php-systems-platform-test';

    /** @var resource|null */
    private static mixed $serveProcess = null;
    private static bool $ownsServe = false;

    private static Database $database;
    private static CacheService $cache;

    public static function setUpBeforeClass(): void
    {
        self::$ownsServe = !self::portAnswers(self::HTTP_PORT);

        if (self::$ownsServe) {
            self::startServe();
        }

        self::$database = Database::fromConfig(new ClientConfig(
            host: self::HOST,
            port: self::DB_PORT,
            connectTimeoutSeconds: 2.0,
        ));
        self::$cache = CacheService::fromConfig([
            'host' => self::HOST,
            'port' => self::CACHE_PORT,
            'timeout' => 2.0,
        ]);
    }

    public static function tearDownAfterClass(): void
    {
        if (isset(self::$database)) {
            self::$database->close();
        }

        if (isset(self::$cache)) {
            self::$cache->close();
        }

        if (self::$ownsServe) {
            self::stopServe();
        }
    }

    public function testHealthEndpointAnswers(): void
    {
        [$status, $body] = $this->request('GET', '/health');

        self::assertSame(200, $status);

        $health = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ok', $health['status']);
    }

    public function testCreateOrderPersistsAndEnqueuesOrderCreatedIntoTheRealJournal(): void
    {
        $order = $this->postOrder('Ada Lovelace', 19.99);

        // The authoritative row landed in the real database.
        $rows = self::$database->read('SELECT * FROM orders WHERE id = ?', [$order['id']]);
        self::assertCount(1, $rows);
        self::assertSame('19.99', $rows[0]['amount']);

        // And the write → enqueue → respond path wrote the READY job into the
        // real append-only journal - the artifact a consumer restores in the
        // next phase.
        $journal = (string) file_get_contents(self::DATA_DIR . '/queue/queue.log');
        self::assertStringContainsString('order.created', $journal);
        self::assertStringContainsString('"state":"READY"', $journal);
        self::assertStringContainsString($order['id'], $journal);
    }

    public function testCacheFirstReadPathIsWarmAfterCreate(): void
    {
        $order = $this->postOrder('Grace Hopper', 42);

        // The synchronous populate-on-write in the handler already warmed the
        // entry, so the very first read is served from the cache.
        [$status, $body, $headers] = $this->request('GET', '/orders/' . $order['id']);
        self::assertSame(200, $status);
        self::assertSame('hit', $this->header($headers, 'x-cache'));

        $frozen = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($order['id'], $frozen['id']);
        self::assertSame('Grace Hopper', $frozen['customer']);
        self::assertSame('42.00', $frozen['amount']);
        self::assertSame('created', $frozen['status']);
    }

    public function testUpdateInvalidatesTheWarmEntryAndTheNextReadRefillsIt(): void
    {
        $order = $this->postOrder('Barbara Liskov', 9.5);
        $this->request('GET', '/orders/' . $order['id']);
        self::assertNotNull(self::$cache->getOrder($order['id']));

        [$status] = $this->request('PUT', '/orders/' . $order['id'], json_encode([
            'status' => 'completed',
        ], JSON_THROW_ON_ERROR));
        self::assertSame(200, $status);

        // invalidate-on-write: the warmed entry is gone until the next read.
        self::assertNull(self::$cache->getOrder($order['id']));

        [$status, $body] = $this->request('GET', '/orders/' . $order['id']);
        self::assertSame(200, $status);
        $refreshed = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('completed', $refreshed['status']);
        self::assertNotNull(self::$cache->getOrder($order['id']));
    }

    public function testUnknownOrderIdIs404(): void
    {
        [$status, , $headers] = $this->request('GET', '/orders/' . $this->missingOrderId());

        self::assertSame(404, $status);
        self::assertSame('miss', $this->header($headers, 'x-cache'));
    }

    public function testOrderCreatedJobFailsWithoutAnOrderId(): void
    {
        $job = $this->dispatchJob(OrderCreatedJob::TYPE);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('order_id');

        new OrderCreatedJob()->execute(new JobContext($job, self::orders(), self::$cache));
    }

    public function testOrderCreatedJobFailsWhenTheOrderIsMissing(): void
    {
        $job = $this->dispatchJob(OrderCreatedJob::TYPE, ['order_id' => $this->missingOrderId()]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not found');

        new OrderCreatedJob()->execute(new JobContext($job, self::orders(), self::$cache));
    }

    public function testOrderCreatedJobWarmsTheCacheFromTheAuthoritativeRow(): void
    {
        $order = $this->postOrder('Margaret Hamilton', 7.25);
        $job = $this->dispatchJob(OrderCreatedJob::TYPE, ['order_id' => $order['id']]);

        $setsBefore = self::$cache->counters()->sets;

        new OrderCreatedJob()->execute(new JobContext($job, self::orders(), self::$cache));

        $cached = self::$cache->getOrder($order['id']);
        self::assertIsArray($cached);
        self::assertSame('Margaret Hamilton', $cached['customer']);
        self::assertSame('7.25', $cached['amount']);
        self::assertSame($setsBefore + 1, self::$cache->counters()->sets);
    }

    public function testOrderCreatedJobCountsABypassWhenTheCacheCannotAnswer(): void
    {
        $order = $this->postOrder('Katherine Johnson', 11);
        $job = $this->dispatchJob(OrderCreatedJob::TYPE, ['order_id' => $order['id']]);

        $unreachableCache = CacheService::fromConfig([
            'host' => self::HOST,
            'port' => 6399,
            'timeout' => 0.2,
        ]);

        new OrderCreatedJob()->execute(new JobContext($job, self::orders(), $unreachableCache));

        self::assertSame(1, $unreachableCache->counters()->bypasses);
    }

    public function testParallelSplitsAHashTaskAcrossWorkers(): void
    {
        [$status, $body] = $this->request('GET', '/parallel?work=4000&split=4');

        self::assertSame(200, $status);

        $result = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(4000, $result['totalIterations']);
        self::assertSame(4, $result['requestedChunks']);
        self::assertSame(4, $result['completedChunks']);
        self::assertSame([], $result['degraded']);
        self::assertNull($result['note']);
        self::assertIsInt($result['wallMicroseconds']);
        self::assertGreaterThanOrEqual(0, $result['wallMicroseconds']);

        self::assertCount(4, $result['chunks']);

        $total = 0;

        foreach ($result['chunks'] as $chunk) {
            self::assertSame(['index', 'iterations', 'microseconds', 'checksum'], array_keys($chunk));
            self::assertGreaterThan(0, $chunk['iterations']);
            self::assertGreaterThanOrEqual(0, $chunk['microseconds']);
            self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $chunk['checksum']);
            $total += $chunk['iterations'];
        }

        self::assertSame(4000, $total);
    }

    public function testParallelRejectsOutOfRangeWorkAndSplit(): void
    {
        [$status, $body] = $this->request('GET', '/parallel?work=1000001&split=4');
        self::assertSame(400, $status);
        self::assertStringContainsString('work', $body);

        [$status, $body] = $this->request('GET', '/parallel?work=1000&split=17');
        self::assertSame(400, $status);
        self::assertStringContainsString('split', $body);
    }

    private static function orders(): OrderService
    {
        return new OrderService(new OrderRepository(self::$database));
    }

    private function dispatchJob(string $type, array $payload = []): \PhpJobQueue\Job\Job
    {
        $clock = new SystemClock();

        return new Producer(
            new InMemoryQueue($clock),
            new JobFactory($clock, new MetricsCollector()),
        )->dispatch($type, $payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function postOrder(string $customer, float|int $amount): array
    {
        [$status, $body] = $this->request('POST', '/orders', json_encode([
            'customer' => $customer,
            'amount' => $amount,
        ], JSON_THROW_ON_ERROR));

        self::assertSame(201, $status);

        $order = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($order);
        self::assertSame($customer, $order['customer']);

        /** @var array<string, mixed> $order */
        return $order;
    }

    private function missingOrderId(): string
    {
        return \Ramsey\Uuid\Uuid::uuid7()->toString();
    }

    private static function startServe(): void
    {
        self::removeTree(self::DATA_DIR);
        self::removeTree(self::LOG_DIR);
        mkdir(self::LOG_DIR, 0o777, true);

        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/bin/platform.php', 'serve'],
            [
                1 => ['file', self::LOG_DIR . '/serve.out', 'a'],
                2 => ['file', self::LOG_DIR . '/serve.err', 'a'],
            ],
            $pipes,
        );

        if (!is_resource($process)) {
            throw new RuntimeException('Could not start the platform serve process.');
        }

        self::$serveProcess = $process;

        if (!self::portAnswers(self::HTTP_PORT, 20.0)) {
            self::stopServe();

            throw new RuntimeException('The platform serve did not start listening in time.');
        }
    }

    private static function stopServe(): void
    {
        if (is_resource(self::$serveProcess)) {
            proc_terminate(self::$serveProcess);
            proc_close(self::$serveProcess);
        }

        self::$serveProcess = null;
    }

    private static function portAnswers(int $port, float $timeoutSeconds = 5.0): bool
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            $socket = @stream_socket_client(
                sprintf('tcp://%s:%d', self::HOST, $port),
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
     * @param list<string>|null $headers
     */
    private function header(?array $headers, string $name): ?string
    {
        foreach ($headers ?? [] as $line) {
            if (stripos($line, $name . ':') === 0) {
                $value = trim(substr($line, strlen($name) + 1));

                return $value;
            }
        }

        return null;
    }

    /**
     * @return array{int, string, list<string>} HTTP status, response body, raw headers
     */
    private function request(string $method, string $path, ?string $body = null): array
    {
        $options = [
            'http' => [
                'method' => $method,
                'header' => 'Content-Type: application/json',
                'content' => $body,
                'timeout' => 5.0,
                'ignore_errors' => true,
            ],
        ];

        $response = @file_get_contents(
            sprintf('http://%s:%d%s', self::HOST, self::HTTP_PORT, $path),
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

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
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
