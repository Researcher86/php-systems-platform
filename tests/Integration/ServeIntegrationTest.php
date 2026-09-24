<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Tests\Integration;

use PhpJobQueue\Dispatcher\JobDispatcher;
use PhpJobQueue\Metrics\MetricsCollector;
use PhpJobQueue\Persistence\FileStorage;
use PhpJobQueue\Producer\JobFactory;
use PhpJobQueue\Producer\Producer;
use PhpJobQueue\Queue\InMemoryQueue;
use PhpJobQueue\Retry\FixedDelayRetry;
use PhpJobQueue\Support\SystemClock;
use PhpJobQueue\Worker\WorkerPool;
use PhpMiniDatabase\Client\ClientConfig;
use PhpSystemsPlatform\Application\Handlers\OrderCreateHandler;
use PhpSystemsPlatform\Cache\CacheService;
use PhpSystemsPlatform\Domain\OrderService;
use PhpSystemsPlatform\Domain\SequentialOrderLoader;
use PhpSystemsPlatform\Http\Request;
use PhpSystemsPlatform\Http\RequestMethod;
use PhpSystemsPlatform\Queue\BackpressurePolicy;
use PhpSystemsPlatform\Queue\JobContext;
use PhpSystemsPlatform\Queue\JobExecutor;
use PhpSystemsPlatform\Queue\JobRegistry;
use PhpSystemsPlatform\Queue\Jobs\OrderCreatedJob;
use PhpSystemsPlatform\Queue\Jobs\OrderProcessJob;
use PhpSystemsPlatform\Queue\QueueJournal;
use PhpSystemsPlatform\Storage\Database;
use PhpSystemsPlatform\Storage\Repositories\CatalogRepository;
use PhpSystemsPlatform\Storage\Repositories\OrderRepository;
use PhpSystemsPlatform\Workers\ConcurrentOrderLoader;
use PhpSystemsPlatform\Workers\ConcurrentTaskRunner;
use PhpSystemsPlatform\Workers\ForkedOrderLoader;
use PhpSystemsPlatform\Workers\WorkerManager;
use PhpSystemsPlatform\Workers\WorkerRegistry;
use PHPUnit\Framework\TestCase;
use PhpWorkerPool\Protocol\Request as WorkerRequest;
use PhpWorkerPool\Sdk\WorkerPoolClient;
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

    public function testAConnectionThatSendsNothingIsClosedAfterTheRequestTimeout(): void
    {
        $socket = stream_socket_client(sprintf('tcp://%s:%d', self::HOST, self::HTTP_PORT), $code, $message, 5.0);
        self::assertIsResource($socket);

        // The configured request_timeout (5s) plus the Master-style periodic
        // sweep's own interval (~1s) plus margin: long enough that, if the
        // idle sweep is actually running, the server has closed this
        // connection by now.
        stream_set_blocking($socket, true);
        stream_set_timeout($socket, 8);

        $data = fread($socket, 1);
        $meta = stream_get_meta_data($socket);
        fclose($socket);

        self::assertSame('', $data);
        self::assertTrue($meta['eof']);
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

    public function testMigratorSeedsTheReferenceCatalogTheLoadersRead(): void
    {
        $catalog = new CatalogRepository(self::$database);

        $customer = $catalog->findCustomer('Ada Lovelace');
        self::assertNotNull($customer);
        self::assertSame('gold', $customer->tier);

        $product = $catalog->findProduct(OrderService::DEFAULT_PRODUCT);
        self::assertNotNull($product);
        self::assertSame('19.99', $product->price);

        $stock = $catalog->findStock(OrderService::DEFAULT_PRODUCT);
        self::assertNotNull($stock);
        self::assertGreaterThan(0, $stock->available);
    }

    public function testCreatedOrdersCarryAProductAndDefaultToTheStandardSku(): void
    {
        $order = $this->postOrder('Ada Lovelace', 19.99);
        self::assertSame(OrderService::DEFAULT_PRODUCT, $order['product']);

        [$status, $body] = $this->request('POST', '/orders', json_encode([
            'customer' => 'Grace Hopper',
            'amount' => 49,
            'product' => 'SKU-PRO',
        ], JSON_THROW_ON_ERROR));

        self::assertSame(201, $status);

        $pro = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('SKU-PRO', $pro['product']);

        $rows = self::$database->read('SELECT product FROM orders WHERE id = ?', [$pro['id']]);
        self::assertSame('SKU-PRO', $rows[0]['product']);
    }

    public function testSequentialLoaderAssemblesTheWholeOrderSnapshot(): void
    {
        $order = $this->postOrder('Ada Lovelace', 19.99);

        $snapshot = new SequentialOrderLoader(self::orders(), new CatalogRepository(self::$database))
            ->load($order['id']);

        self::assertNotNull($snapshot);
        self::assertSame($order['id'], $snapshot->order->id);
        self::assertSame('gold', $snapshot->customer?->tier);
        self::assertSame('Standard plan', $snapshot->product?->title);
        self::assertSame(OrderService::DEFAULT_PRODUCT, $snapshot->stock?->sku);
        self::assertGreaterThan(0, (int) $snapshot->stock?->available);
    }

    public function testSequentialLoaderAnswersNullForAnUnknownOrder(): void
    {
        $snapshot = new SequentialOrderLoader(self::orders(), new CatalogRepository(self::$database))
            ->load($this->missingOrderId());

        self::assertNull($snapshot);
    }

    public function testSnapshotLeavesUnseededReferenceDataEmptyInsteadOfFailing(): void
    {
        // A customer nobody seeded, and a sku that is not in the catalog: the
        // order still loads, the missing parts are simply absent.
        [$status, $body] = $this->request('POST', '/orders', json_encode([
            'customer' => 'Nobody In The Catalog',
            'amount' => 1,
            'product' => 'SKU-GHOST',
        ], JSON_THROW_ON_ERROR));
        self::assertSame(201, $status);

        $order = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $snapshot = new SequentialOrderLoader(self::orders(), new CatalogRepository(self::$database))
            ->load($order['id']);

        self::assertNotNull($snapshot);
        self::assertNull($snapshot->customer);
        self::assertNull($snapshot->product);
        self::assertNull($snapshot->stock);
    }

    public function testConcurrentLoaderAnswersExactlyWhatTheSequentialOneDoes(): void
    {
        $order = $this->postOrder('Grace Hopper', 49.0);

        $sequential = new SequentialOrderLoader(self::orders(), new CatalogRepository(self::$database))
            ->load($order['id']);
        $concurrent = new ConcurrentOrderLoader(self::orders(), $this->taskRunner())
            ->load($order['id']);

        // Same snapshot, different execution model - the whole premise of the
        // comparison.
        self::assertEquals($sequential, $concurrent);
    }

    public function testConcurrentLoaderAnswersNullForAnUnknownOrder(): void
    {
        self::assertNull(new ConcurrentOrderLoader(self::orders(), $this->taskRunner())
            ->load($this->missingOrderId()));
    }

    public function testFanningOutTheIndependentPartsOverlapsTheirWait(): void
    {
        $order = $this->postOrder('Alan Turing', 99.0);
        $catalog = new CatalogRepository(self::$database);

        // 100ms of simulated external latency per part: three parts cost
        // ~300ms one after another, but only one such wait when they are in
        // flight together.
        $sequentialStarted = microtime(true);
        new SequentialOrderLoader(self::orders(), $catalog, 100)->load($order['id']);
        $sequentialSeconds = microtime(true) - $sequentialStarted;

        $concurrentStarted = microtime(true);
        new ConcurrentOrderLoader(self::orders(), $this->taskRunner(), 100)->load($order['id']);
        $concurrentSeconds = microtime(true) - $concurrentStarted;

        self::assertGreaterThan(0.3, $sequentialSeconds);
        self::assertLessThan($sequentialSeconds, $concurrentSeconds);
    }

    public function testOrderProcessJobCompletesAnOrderItsStockCanCover(): void
    {
        $order = $this->postOrder('Ada Lovelace', 19.99);
        $job = $this->dispatchJob(OrderProcessJob::TYPE, ['order_id' => $order['id']]);

        new OrderProcessJob()->execute(new JobContext($job, self::orders(), self::$cache, self::loader()));

        $rows = self::$database->read('SELECT status FROM orders WHERE id = ?', [$order['id']]);
        self::assertSame('completed', $rows[0]['status']);

        // The status changed, so the entry warmed on write must not survive.
        self::assertNull(self::$cache->getOrder($order['id']));
    }

    public function testOrderProcessJobCancelsAnOrderNothingCanBeShippedFor(): void
    {
        [, $body] = $this->request('POST', '/orders', json_encode([
            'customer' => 'Ada Lovelace',
            'amount' => 5,
            'product' => 'SKU-GHOST',
        ], JSON_THROW_ON_ERROR));
        $order = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        $job = $this->dispatchJob(OrderProcessJob::TYPE, ['order_id' => $order['id']]);
        new OrderProcessJob()->execute(new JobContext($job, self::orders(), self::$cache, self::loader()));

        $rows = self::$database->read('SELECT status FROM orders WHERE id = ?', [$order['id']]);
        self::assertSame('cancelled', $rows[0]['status']);
    }

    public function testOrderProcessJobFailsWhenTheOrderIsMissing(): void
    {
        $job = $this->dispatchJob(OrderProcessJob::TYPE, ['order_id' => $this->missingOrderId()]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not found');

        new OrderProcessJob()->execute(new JobContext($job, self::orders(), self::$cache, self::loader()));
    }

    public function testTheQueuePathRunsAnOrderProcessJobEndToEnd(): void
    {
        $order = $this->postOrder('Grace Hopper', 49.0);
        $clock = new SystemClock();

        new Producer(
            new InMemoryQueue($clock, new FileStorage(self::DATA_DIR . '/queue/queue.log')),
            new JobFactory($clock, new MetricsCollector()),
        )->dispatch(OrderProcessJob::TYPE, ['order_id' => $order['id']], maxAttempts: 3);

        $this->drainQueue();

        $rows = self::$database->read('SELECT status FROM orders WHERE id = ?', [$order['id']]);
        self::assertSame('completed', $rows[0]['status']);
    }

    public function testAWorkerStuckPastItsExecutionTimeoutIsKilledAndReplaced(): void
    {
        $socketPath = '/tmp/php-exectimeout-' . uniqid('', true) . '.sock';
        $dir = self::LOG_DIR . '/exectimeout-' . uniqid('', true);
        mkdir($dir, 0o777, true);

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
                'WORKER_POOL_MIN' => '1',
                'WORKER_POOL_MAX' => '1',
                'WORKER_POOL_TIMEOUT' => '3',
                'WORKER_POOL_EXECUTION_TIMEOUT' => '1',
            ],
        );

        self::assertIsResource($pool);

        try {
            $deadline = microtime(true) + 10.0;

            while (microtime(true) < $deadline) {
                $socket = @stream_socket_client(sprintf('unix://%s', $socketPath), $code, $message, 0.2);

                if ($socket !== false) {
                    fclose($socket);
                    break;
                }

                usleep(100_000);
            }

            $client = new WorkerPoolClient($socketPath, 3.0);

            // The pool's only worker, held on a task far longer than
            // anything else in this test waits for - if the pool ever falls
            // back to just waiting the original worker out, this is long
            // enough that it cannot. Fired and never awaited: by the time it
            // would answer, the worker holding it is gone.
            $client->send(new WorkerRequest('sleep', ['ms' => 30_000]));

            // Past the execution timeout, plus the Master's own ~1s sweep
            // interval: enough for terminateStuckWorkers() to have noticed,
            // killed the worker, and forked its replacement.
            usleep(2_500_000);

            // A fresh request, with the same short client timeout as the
            // pool's own worker-operation timeout. It can only succeed this
            // fast if a NEW worker answered - the original is still 27+
            // seconds from finishing its sleep, so a pool that only ever
            // waits workers out would make this request_timeout instead.
            $answer = $client->call(new WorkerRequest('ping', []));

            self::assertTrue($answer['pong']);
        } finally {
            proc_terminate($pool);
            proc_close($pool);
        }
    }

    public function testForkedLoaderAnswersExactlyWhatTheSequentialOneDoes(): void
    {
        $order = $this->postOrder('Ada Lovelace', 19.99);

        $sequential = new SequentialOrderLoader(self::orders(), new CatalogRepository(self::$database))
            ->load($order['id']);
        $forked = self::forkedLoader()->load($order['id']);

        self::assertEquals($sequential, $forked);
    }

    public function testForkedLoaderAnswersNullForAnUnknownOrder(): void
    {
        self::assertNull(self::forkedLoader()->load($this->missingOrderId()));
    }

    public function testForkedLoaderOverlapsTheWaitReapsItsChildrenAndLeavesTheParentConnected(): void
    {
        $order = $this->postOrder('Alan Turing', 99.0);

        $sequentialStarted = microtime(true);
        new SequentialOrderLoader(self::orders(), new CatalogRepository(self::$database), 100)
            ->load($order['id']);
        $sequentialSeconds = microtime(true) - $sequentialStarted;

        $forkedStarted = microtime(true);
        $snapshot = self::forkedLoader(100)->load($order['id']);
        $forkedSeconds = microtime(true) - $forkedStarted;

        self::assertNotNull($snapshot);
        self::assertSame('bronze', $snapshot->customer?->tier);

        // Three 100ms waits that happened at the same time, not one after
        // another - forking is what made them overlap. Measured against the
        // sequential loader in the same environment rather than a fixed
        // budget, so a slow machine cannot turn the claim into a flake.
        self::assertGreaterThan(0.3, $sequentialSeconds);
        self::assertLessThan($sequentialSeconds, $forkedSeconds);

        // Nothing exited is left unreaped: every child was waited for.
        self::assertLessThanOrEqual(0, pcntl_waitpid(-1, $status, WNOHANG));

        // And the parent's own database connection survived the forks - the
        // children never touched it, they opened their own.
        self::assertNotNull(self::orders()->getOrder($order['id']));
    }

    public function testOrderCreationIsRejectedWithoutTouchingAnythingWhenTheQueueIsAtCapacity(): void
    {
        $scratchLog = self::LOG_DIR . '/backpressure.log';
        @unlink($scratchLog);
        $this->publishJobs($scratchLog, 2);

        $handler = new OrderCreateHandler(
            self::orders(),
            self::$cache,
            new BackpressurePolicy(new QueueJournal($scratchLog), maxSize: 2),
        );

        $before = self::$database->read('SELECT COUNT(*) AS n FROM orders')[0]['n'];

        $response = $handler(new Request(RequestMethod::POST, '/orders', body: json_encode([
            'customer' => 'Overloaded Customer',
            'amount' => 1,
        ], JSON_THROW_ON_ERROR)), []);

        self::assertSame(429, $response->status);
        self::assertNotNull($response->header('Retry-After'));

        $payload = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(2, $payload['queueDepth']);
        self::assertSame(2, $payload['queueMaxSize']);

        // Rejected means rejected: nothing was persisted and nothing was
        // enqueued because of this request.
        $after = self::$database->read('SELECT COUNT(*) AS n FROM orders')[0]['n'];
        self::assertSame($before, $after);
    }

    public function testOrderCreationSucceedsWhenTheQueueIsUnderCapacity(): void
    {
        $scratchLog = self::LOG_DIR . '/backpressure-ok.log';
        @unlink($scratchLog);
        $this->publishJobs($scratchLog, 1);

        $handler = new OrderCreateHandler(
            self::orders(),
            self::$cache,
            new BackpressurePolicy(new QueueJournal($scratchLog), maxSize: 2),
        );

        $response = $handler(new Request(RequestMethod::POST, '/orders', body: json_encode([
            'customer' => 'Room To Spare',
            'amount' => 1,
        ], JSON_THROW_ON_ERROR)), []);

        self::assertSame(201, $response->status);
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

    public function testQueueStatusEndpointReportsTheJournalCounters(): void
    {
        // Delta-based: earlier tests may already have published jobs into the
        // shared journal, so assert on what THIS post adds.
        $before = new QueueJournal(self::DATA_DIR . '/queue/queue.log')->snapshot();

        $this->postOrder('Dennis Ritchie', 6.0);

        [$status, $body] = $this->request('GET', '/queue/status');
        self::assertSame(200, $status);

        $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('queue', $payload);
        self::assertSame(
            ['depth', 'published', 'completed', 'failed', 'retried'],
            array_keys($payload['queue']),
        );

        // Exactly one more published job, still ready for a consumer.
        self::assertSame($before['published'] + 1, $payload['queue']['published']);
        self::assertSame($before['depth'] + 1, $payload['queue']['depth']);
        self::assertSame($before['completed'], $payload['queue']['completed']);
        self::assertSame($before['failed'], $payload['queue']['failed']);
        self::assertSame($before['retried'], $payload['queue']['retried']);
    }

    public function testConsumerReplaysTheJournalAndCompletesEveryReadyJob(): void
    {
        $order = $this->postOrder('Alan Kay', 3.5);

        $this->drainQueue();

        $rows = new QueueJournal(self::DATA_DIR . '/queue/queue.log')->rows();

        // The journal is keyed by job id; find this order's job by payload.
        $jobRow = null;

        foreach ($rows as $data) {
            if (($data['payload']['order_id'] ?? null) === $order['id']) {
                $jobRow = $data;
                break;
            }
        }

        self::assertNotNull($jobRow);
        self::assertSame('COMPLETED', $jobRow['state']);
        self::assertSame(1, $jobRow['attempts']);

        // Everything published before the drain is terminal now: nothing is
        // left waiting for a worker.
        $snapshot = new QueueJournal(self::DATA_DIR . '/queue/queue.log')->snapshot();
        self::assertSame(0, $snapshot['depth']);
        self::assertSame(
            $snapshot['published'],
            $snapshot['completed'] + $snapshot['failed'],
        );
    }

    public function testConsumerRejectsAMalformedJobWithoutBurningItsRetryBudget(): void
    {
        $clock = new SystemClock();
        $logPath = self::DATA_DIR . '/queue/queue.log';

        // The journal may already hold jobs from earlier tests, so assert on
        // the delta this malformed job adds.
        $before = new QueueJournal($logPath)->snapshot();

        // An order.created job without an order_id can never work (PLAN
        // Step 19) - published straight into the real journal, exactly as
        // a malformed job would arrive from anywhere else.
        $job = new Producer(
            new InMemoryQueue($clock, new FileStorage($logPath)),
            new JobFactory($clock, new MetricsCollector()),
        )->dispatch(OrderCreatedJob::TYPE, [], maxAttempts: 3);

        $this->drainQueue();

        $rows = new QueueJournal($logPath)->rows();
        self::assertArrayHasKey((string) $job->getId(), $rows);
        self::assertSame('FAILED', $rows[(string) $job->getId()]['state']);

        // Rejected before it ever reached a worker: one delivery consumed,
        // not the full three-attempt budget a genuine retry would spend.
        self::assertSame(1, $rows[(string) $job->getId()]['attempts']);

        // Failed, but never actually retried - the whole point.
        $snapshot = new QueueJournal($logPath)->snapshot();
        self::assertSame($before['failed'] + 1, $snapshot['failed']);
        self::assertSame($before['retried'], $snapshot['retried']);
    }

    public function testConsumerStillRetriesAWellFormedJobUntilItsBudgetIsSpent(): void
    {
        $clock = new SystemClock();
        $logPath = self::DATA_DIR . '/queue/queue.log';

        $before = new QueueJournal($logPath)->snapshot();

        // A payload ValidatesPayload cannot rule out - well-formed, but
        // pointing at an order that does not exist. Answering that needs a
        // database read, so it is not rejected pre-flight and still gets
        // the normal retry-then-fail treatment.
        $job = new Producer(
            new InMemoryQueue($clock, new FileStorage($logPath)),
            new JobFactory($clock, new MetricsCollector()),
        )->dispatch(OrderCreatedJob::TYPE, ['order_id' => $this->missingOrderId()], maxAttempts: 3);

        $this->drainQueue();

        $rows = new QueueJournal($logPath)->rows();
        self::assertSame('FAILED', $rows[(string) $job->getId()]['state']);
        self::assertSame(3, $rows[(string) $job->getId()]['attempts']);

        $snapshot = new QueueJournal($logPath)->snapshot();
        self::assertSame($before['failed'] + 1, $snapshot['failed']);
        self::assertSame($before['retried'] + 1, $snapshot['retried']);

        // The job metadata the queue component itself keeps directly now
        // (PLAN Step 19): PhpJobQueue\Job\Job's own started_at/completed_at/
        // last_error, stamped by JobDispatcher around each delivery -
        // describing the LAST attempt only, the one the component tracks.
        $row = $rows[(string) $job->getId()];

        self::assertNotNull($row['startedAt']);
        self::assertNotNull($row['completedAt']);
        self::assertGreaterThanOrEqual($row['startedAt'], $row['completedAt']);
        self::assertStringContainsString('not found', (string) $row['lastError']);
    }

    public function testJobMetadataRecordsACleanRunWithNoError(): void
    {
        $order = $this->postOrder('Job Metadata', 5);
        $clock = new SystemClock();
        $logPath = self::DATA_DIR . '/queue/queue.log';

        $job = new Producer(
            new InMemoryQueue($clock, new FileStorage($logPath)),
            new JobFactory($clock, new MetricsCollector()),
        )->dispatch(OrderCreatedJob::TYPE, ['order_id' => $order['id']], maxAttempts: 3);

        $this->drainQueue();

        $rows = new QueueJournal($logPath)->rows();
        $row = $rows[(string) $job->getId()];

        self::assertSame('COMPLETED', $row['state']);
        self::assertNotNull($row['startedAt']);
        self::assertNotNull($row['completedAt']);
        self::assertNull($row['lastError']);
    }

    public function testLiveConsumerPicksUpJobsPublishedWhileItRuns(): void
    {
        $logPath = self::DATA_DIR . '/queue/queue.log';
        $order = $this->postOrder('Bjarne Stroustrup', 12.75);

        // Run the real queue:consume CLI as a subprocess, then publish a job
        // after it has started: the consumer's loop re-reads the journal, so
        // the job must be picked up and completed without a restart.
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/bin/platform.php', 'queue:consume'],
            [
                1 => ['file', self::LOG_DIR . '/consume.out', 'a'],
                2 => ['file', self::LOG_DIR . '/consume.err', 'a'],
            ],
            $pipes,
        );

        self::assertIsResource($process);

        try {
            $clock = new SystemClock();
            $job = new Producer(
                new InMemoryQueue($clock, new FileStorage($logPath)),
                new JobFactory($clock, new MetricsCollector()),
            )->dispatch(OrderCreatedJob::TYPE, ['order_id' => $order['id']], maxAttempts: 3);

            $deadline = microtime(true) + 15.0;
            $state = null;

            do {
                $rows = new QueueJournal($logPath)->rows();
                $state = $rows[(string) $job->getId()]['state'] ?? null;
                usleep(100_000);
            } while (microtime(true) < $deadline && $state !== 'COMPLETED');

            self::assertSame('COMPLETED', $state, sprintf(
                'Consumer output: %s',
                (string) file_get_contents(self::LOG_DIR . '/consume.out'),
            ));
        } finally {
            proc_terminate($process);
            proc_close($process);
        }
    }

    public function testWorkerPoolExecutesAQueueJob(): void
    {
        $order = $this->postOrder('Linus Torvalds', 4.5);
        $job = $this->dispatchJob(OrderCreatedJob::TYPE, ['order_id' => $order['id']]);

        // The Worker Manager hands the job to the running pool; a forked
        // worker executes it and warms the cache from the authoritative row.
        $this->workerManager()->execute($job);

        $cached = self::$cache->getOrder($order['id']);
        self::assertIsArray($cached);
        self::assertSame('Linus Torvalds', $cached['customer']);
        self::assertSame('4.50', $cached['amount']);
    }

    public function testWorkerPoolRejectsAMalformedJob(): void
    {
        $job = $this->dispatchJob(OrderCreatedJob::TYPE, []);

        // A worker failure is answered as job_failed, which the manager turns
        // into a failed attempt the queue can retry - not a silent success.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Worker rejected job');

        $this->workerManager()->execute($job);
    }

    public function testWorkerRegistryAttributesCompletedJobsToForwarders(): void
    {
        $order = $this->postOrder('Edsger Dijkstra', 6.75);
        $clock = new SystemClock();
        $logPath = self::DATA_DIR . '/queue/queue.log';

        // One READY job, straight into the real journal.
        new Producer(
            new InMemoryQueue($clock, new FileStorage($logPath)),
            new JobFactory($clock, new MetricsCollector()),
        )->dispatch(OrderCreatedJob::TYPE, ['order_id' => $order['id']], maxAttempts: 3);

        $queue = InMemoryQueue::restoreFromStorage(new FileStorage($logPath), $clock);
        $journal = new QueueJournal($logPath);

        $workerManager = null;
        $pool = new WorkerPool(
            size: 2,
            handler: function (\PhpJobQueue\Job\Job $job) use (&$workerManager): mixed {
                $workerManager ??= $this->workerManager();

                $workerManager->execute($job);

                return null;
            },
        );
        $registry = new WorkerRegistry($pool, $journal);
        $dispatcher = new JobDispatcher(
            queue: $queue,
            workerPool: $pool,
            retryPolicy: new FixedDelayRetry(0),
            clock: $clock,
            visibilityTimeout: 2,
            storage: new FileStorage($logPath),
        );
        $dispatcher->start();

        // One consumer tick, mirroring QueueConsumer::tick(): capture between
        // dispatch and collect, settle after the answer lands. collect() here
        // waits for the pool round-trip (a single blocking pass).
        $dispatcher->dispatchPending();
        $registry->capture();
        $dispatcher->collect(5.0);
        $registry->settle();

        $pool->shutdown();

        // The forwarder that held the job was credited with it.
        $completed = array_sum(array_map(
            static fn (array $worker): int => $worker['tasks_completed'],
            $registry->snapshot(),
        ));
        self::assertGreaterThanOrEqual(1, $completed);

        foreach ($registry->snapshot() as $worker) {
            self::assertSame(
                ['id', 'pid', 'state', 'current_job', 'started_at', 'tasks_completed', 'tasks_failed'],
                array_keys($worker),
            );
        }
    }

    public function testWorkersStatusExposesTheConsumerForwarders(): void
    {
        $logPath = self::DATA_DIR . '/queue/queue.log';
        $statusPath = self::DATA_DIR . '/worker/workers.status.json';
        $order = $this->postOrder('Alan Turing', 9.99);

        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/bin/platform.php', 'queue:consume'],
            [
                1 => ['file', self::LOG_DIR . '/consume.out', 'a'],
                2 => ['file', self::LOG_DIR . '/consume.err', 'a'],
            ],
            $pipes,
        );

        self::assertIsResource($process);

        try {
            // A job the consumer's forwarders must complete.
            $clock = new SystemClock();
            $job = new Producer(
                new InMemoryQueue($clock, new FileStorage($logPath)),
                new JobFactory($clock, new MetricsCollector()),
            )->dispatch(OrderCreatedJob::TYPE, ['order_id' => $order['id']], maxAttempts: 3);

            // Wait until the snapshot shows a worker that completed it.
            $deadline = microtime(true) + 15.0;
            $workers = [];

            do {
                $decoded = is_file($statusPath) ? json_decode((string) file_get_contents($statusPath), true) : null;
                $workers = is_array($decoded) ? $decoded : [];
                $done = array_sum(array_map(
                    static fn (array $worker): int => $worker['tasks_completed'],
                    $workers,
                ));
                usleep(100_000);
            } while (microtime(true) < $deadline && $done < 1);

            self::assertGreaterThanOrEqual(1, $done, sprintf(
                'Consumer output: %s',
                (string) file_get_contents(self::LOG_DIR . '/consume.out'),
            ));

            foreach ($workers as $worker) {
                self::assertSame(
                    ['id', 'pid', 'state', 'current_job', 'started_at', 'tasks_completed', 'tasks_failed'],
                    array_keys($worker),
                );
            }

            // And GET /workers reads the same snapshot over HTTP.
            [$status, $body] = $this->request('GET', '/workers');
            self::assertSame(200, $status);
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            self::assertTrue($payload['running']);
            self::assertNotEmpty($payload['workers']);
        } finally {
            proc_terminate($process);
            proc_close($process);
        }
    }

    public function testOrdersCompareReportsBothExecutionModels(): void
    {
        $out = self::LOG_DIR . '/compare.out';
        $err = self::LOG_DIR . '/compare.err';
        @unlink($out);
        @unlink($err);

        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/bin/platform.php', 'orders:compare', '2', '50'],
            [
                1 => ['file', $out, 'a'],
                2 => ['file', $err, 'a'],
            ],
            $pipes,
        );

        self::assertIsResource($process);
        $code = proc_close($process);

        self::assertSame(0, $code, (string) file_get_contents($err));

        $output = (string) file_get_contents($out);
        self::assertStringContainsString('local reads only', $output);
        self::assertStringContainsString('50 ms simulated', $output);

        foreach (['sequential', 'forked', 'pooled'] as $model) {
            self::assertStringContainsString($model, $output);
        }

        // Two blocks, each reporting the two fan-out models against the
        // sequential baseline - and once the parts wait, both beat it.
        preg_match_all('/([\d.]+)x/', $output, $speedups);
        self::assertCount(4, $speedups[1]);
        self::assertGreaterThan(1.0, (float) $speedups[1][2]);
        self::assertGreaterThan(1.0, (float) $speedups[1][3]);
    }

    public function testQueueJobCommandShowsMetadataAndLastAttempt(): void
    {
        $order = $this->postOrder('Queue Job Command', 3);
        $clock = new SystemClock();
        $logPath = self::DATA_DIR . '/queue/queue.log';

        $job = new Producer(
            new InMemoryQueue($clock, new FileStorage($logPath)),
            new JobFactory($clock, new MetricsCollector()),
        )->dispatch(OrderCreatedJob::TYPE, ['order_id' => $order['id']], maxAttempts: 3);

        $this->drainQueue();

        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/bin/platform.php', 'queue:job', (string) $job->getId()],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        self::assertIsResource($process);

        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        self::assertSame(0, $code, $errors);
        self::assertStringContainsString((string) $job->getId(), $output);
        self::assertStringContainsString('order.created', $output);
        self::assertStringContainsString('COMPLETED', $output);
        self::assertStringContainsString('last attempt', $output);
        self::assertStringContainsString('error     -', $output);
    }

    public function testQueueJobCommandReportsAnUnknownId(): void
    {
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/bin/platform.php', 'queue:job', $this->missingOrderId()],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        self::assertIsResource($process);

        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        self::assertSame(1, $code);
        self::assertStringContainsString('No job', $errors);
    }

    public function testQueueBenchmarkRunsTheFullPipelineAndReportsMetrics(): void
    {
        $out = self::LOG_DIR . '/bench.out';
        $err = self::LOG_DIR . '/bench.err';
        @unlink($out);
        @unlink($err);

        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/bin/platform.php', 'benchmark', '20', '2'],
            [
                1 => ['file', $out, 'a'],
                2 => ['file', $err, 'a'],
            ],
            $pipes,
        );

        self::assertIsResource($process);
        $code = proc_close($process);

        self::assertSame(0, $code, (string) file_get_contents($err));

        $output = (string) file_get_contents($out);
        self::assertStringContainsString('total processing time', $output);
        self::assertStringContainsString('throughput', $output);
        self::assertStringContainsString('average latency', $output);
        self::assertStringContainsString('p95 latency', $output);
        self::assertStringContainsString('worker utilization', $output);

        preg_match('/total processing time\s+([\d.]+)s/', $output, $total);
        self::assertGreaterThan(0.0, (float) $total[1]);

        preg_match('/throughput\s+([\d.]+) jobs/', $output, $throughput);
        self::assertGreaterThan(0.0, (float) $throughput[1]);
    }

    private function taskRunner(): ConcurrentTaskRunner
    {
        $config = require dirname(__DIR__, 2) . '/config/platform.php';
        $workers = $config['workers'];

        return new ConcurrentTaskRunner(new WorkerPoolClient(
            (string) $workers['socket'],
            (float) $workers['task_timeout'],
        ));
    }

    private function workerManager(): WorkerManager
    {
        $config = require dirname(__DIR__, 2) . '/config/platform.php';
        $workers = $config['workers'];

        return new WorkerManager(new WorkerPoolClient(
            (string) $workers['socket'],
            (float) $workers['task_timeout'],
        ));
    }

    private static function orders(): OrderService
    {
        return new OrderService(new OrderRepository(self::$database));
    }

    private static function forkedLoader(int $simulatedLatencyMs = 0): ForkedOrderLoader
    {
        return new ForkedOrderLoader(
            self::orders(),
            ['host' => self::HOST, 'port' => self::DB_PORT, 'timeout' => 2.0],
            $simulatedLatencyMs,
        );
    }

    private static function loader(): SequentialOrderLoader
    {
        return new SequentialOrderLoader(self::orders(), new CatalogRepository(self::$database));
    }

    /**
     * Publish $count bare bench.noop jobs into a scratch journal - just
     * enough to make BackpressurePolicy see a given depth, nothing more.
     */
    private function publishJobs(string $logPath, int $count): void
    {
        $clock = new SystemClock();
        $producer = new Producer(
            new InMemoryQueue($clock, new FileStorage($logPath)),
            new JobFactory($clock, new MetricsCollector()),
        );

        for ($i = 0; $i < $count; $i++) {
            $producer->dispatch(\PhpSystemsPlatform\Queue\Jobs\NoopJob::TYPE);
        }
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
     * Run the same machinery queue:consume wires: restore the journal into a
     * queue, fork a small WorkerPool whose handlers run platform jobs through
     * JobExecutor, and dispatch every ready job to completion. Returns when
     * no job is left that a worker can take.
     */
    private function drainQueue(): void
    {
        $clock = new SystemClock();
        $logPath = self::DATA_DIR . '/queue/queue.log';
        $storage = new FileStorage($logPath);

        $queue = InMemoryQueue::restoreFromStorage($storage, $clock);

        $executor = new JobExecutor(
            ['host' => self::HOST, 'port' => self::DB_PORT, 'timeout' => 2.0],
            ['host' => self::HOST, 'port' => self::CACHE_PORT, 'timeout' => 2.0],
        );
        $pool = new WorkerPool(
            size: 2,
            handler: fn (\PhpJobQueue\Job\Job $job): mixed => $executor->__invoke($job),
        );
        $dispatcher = new JobDispatcher(
            queue: $queue,
            workerPool: $pool,
            retryPolicy: new FixedDelayRetry(0),
            clock: $clock,
            visibilityTimeout: 2,
            storage: new FileStorage($logPath),
            shouldRetry: JobRegistry::shouldRetry(),
        );

        $dispatcher->start();

        while ($dispatcher->dispatchNext()) {
            // Drain until nothing is ready to dispatch.
        }

        $pool->shutdown();
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
