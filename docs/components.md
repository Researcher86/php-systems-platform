# Component APIs

This file fixes the surface of each integrated component the way the platform
uses it. It is written once, from the installed sources under `vendor/tanat/`,
so every later phase wires its adapter against a known contract instead of
re-reading the components' internals each time.

All five are Composer `vcs` dependencies on `https://github.com/Researcher86`
at `dev-master`. The components stay the owners of their mechanics; the
platform only ever calls the entry points listed here.

## Common defaults

| Component                     | Entry point                       | Default listen addr |
| ----------------------------- | --------------------------------- | ------------------- |
| php-mini-http-server          | HTTP server, port 8080            | `127.0.0.1:8080`    |
| php-mini-database             | `bin/minidb-server start`         | `127.0.0.1:5433`    |
| php-mini-cache                | `bin/server.php` (`CACHE_PORT`)   | `127.0.0.1:6380`    |
| php-job-queue                 | none (in-process)                 | -                   |
| php-worker-pool               | `Master` Unix socket              | `/tmp/php-worker-pool.sock` |

`config/platform.php` mirrors these addresses; wherever a value differs from
what a component defaults to, the component's own default wins.

## php-mini-http-server

Namespace `PhpMiniHttpServer`. The platform's server-facing boundary.

```php
use PhpMiniHttpServer\Server\Server;
use PhpMiniHttpServer\Server\ServerConfig;
use PhpMiniHttpServer\Http\Handler\RequestHandler;

$config = new ServerConfig(
    host: '127.0.0.1',
    port: 8080,
    // backlog, connectionTimeout, headerTimeout, maxHeaderBytes,
    // maxBodyBytes, maxConnections all have component defaults.
);

$server = new Server($config);
$server->start();
$server->socket();   // the non-blocking listen socket for the event loop
```

The application implements the request boundary:

```php
interface RequestHandler {
    public function handle(HttpRequest $request): HttpResponse;
}
```

- `HttpRequest`: `method`, `target`, `version`, `headers`, `body`;
  helpers `path()`, `query()`, `header()`.
- `HttpResponse`: made with `ResponseFactory::text()` / `json()` / `empty()`
  (JSON is encoded with `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`).
  `HttpStatusCode` carries the numeric codes.
- Wiring (mirrors `bin/server.php`): `SelectLoop` -> `onReadable` on the
  server socket -> `ConnectionHandler(loop, server socket, connection, parser,
  application, encoder, metrics, logger)` -> `run()`.
- `Router` and middleware pipeline components exist internally; the platform
  brings its **own** router (see below) and only implements `RequestHandler`.

## php-mini-database

Namespace `PhpMiniDatabase`. Client side only — the server is started as a
process and talked to over TCP.

```php
use PhpMiniDatabase\Client\ClientConfig;
use PhpMiniDatabase\Client\Connection;
use PhpMiniDatabase\Client\ConnectionPool;

$config = new ClientConfig(
    host: '127.0.0.1',
    port: 5433,
    // user/password default '' — auth is off by default (dev mode).
    connectTimeoutSeconds: 5.0, readTimeoutSeconds: 30.0,
    writeTimeoutSeconds: 30.0,
);

$connection = Connection::connect($config);
$pool = new ConnectionPool($config, maxConnections: 10);
$connection = $pool->acquire();   // throws past maxConnections; reconnects dead ones
$pool->release($connection);
```

Methods the platform uses:

- `query(string $sql, array $parameters = []): ResultSet`
- `execute(string $sql, array $parameters = []): ?int` — affected rows
- `prepare($sql): Statement` (+ `execute([...])`, `close()`)
- `beginTransaction(?IsolationLevel)/commit()/rollback()/savepoint($name)`
- `showStatus(): ResultSet`, `showConnections(): ResultSet`, `isAlive()`
- `ResultSet`: `fetch(): ?array`, `fetchAll()`, iteration, `count()`,
  `affectedRows(): ?int`

Placeholders in `query()` are positional (`$parameters` is `list<mixed>`).

## php-mini-cache

Namespace `PhpMiniCache`. Client side only (RESP over TCP).

```php
use PhpMiniCache\Sdk\CacheClient;

$cache = new CacheClient(host: '127.0.0.1', port: 6380, timeoutSeconds: 2.0);
```

Methods the platform uses:

- `set(string $key, string $value, ?int $ttlSeconds = null): void`
- `get(string $key): ?string` — null = missing **or** expired
- `delete(string $key, ...): int`, `exists(string $key, ...): int`
- `increment(string $key): int`, `info(): string`
- `publish($channel, $message)`, `subscribe($channel)`, `nextMessage(?float)`
  for Pub/Sub based cache invalidation (optional, not required by the plan)
- `pipeline(array $commands): array` when several values must change together
- Exceptions: `CacheClientException` + `CommandFailedException`,
  `ConnectionFailedException`, `ConnectionLostException`, `ReplyTimedOutException`.

## php-job-queue

Namespace `PhpJobQueue`. In-process; the platform owns both producer and
consumer sides.

```php
use PhpJobQueue\Queue\InMemoryQueue;
use PhpJobQueue\Producer\Producer;
use PhpJobQueue\Producer\JobFactory;
use PhpJobQueue\Dispatcher\JobDispatcher;
use PhpJobQueue\Worker\WorkerPool;
use PhpJobQueue\Master\QueueRuntime;
use PhpJobQueue\Retry\ExponentialBackoffRetry;
use PhpJobQueue\Metrics\MetricsCollector;
use PhpJobQueue\Support\SystemClock;
use PhpJobQueue\Job\Job;
use PhpJobQueue\Job\JobPriority;

$clock = new SystemClock();
$metrics = new MetricsCollector();
$queue = new InMemoryQueue($clock);

// produce
$producer = new Producer($queue, new JobFactory($clock, $metrics));
$producer->dispatch('order.created', ['order_id' => 123], delay: 0.0); // or priority / idempotencyKey

// consume (long-running)
$pool = new WorkerPool(workers: 4, handler: static fn (Job $job): void => /* dispatch by $job->getType() */, $metrics);
$dispatcher = new JobDispatcher(
    queue: $queue, workerPool: $pool,
    retryPolicy: new ExponentialBackoffRetry(),
    clock: $clock, visibilityTimeout: 30, metrics: $metrics,
);
$runtime = new QueueRuntime($dispatcher, $clock, shutdownGrace: 20.0);
$runtime->run();          // SIGTERM/SIGINT stop it gracefully (inner WorkerPool forks)
```

Key facts:

- Attempts count **deliveries**; a retry-eligible failure returns the job to
  READY behind the retry policy, a spent one goes to FAILED (+ DLQ if given).
- `Job::create(type, payload, maxAttempts, clock, priority, idempotencyKey)`
  and `Producer::dispatch()` (`delay`, `priority`, `idempotencyKey`) are the
  two birth paths; the platform uses `Producer::dispatch()` for publishing
  and a real `Job` for metadata (`getType()`, `getPayload()`, `getId()`,
  `getAttempts()`, `getMaxAttempts()`, `getIdempotencyKey()`).
- `JobDispatcher::observe(): QueueMetrics` yields ready/delayed/processing/
  workers/busyWorkers/deadLettered — the platform's `queue:status`.
- `JobPriority`, `JobResult`, `DeadLetterQueue`, `JobStorage`,
  `Scheduler\DelayedJobScheduler` exist for the later reliability phases.

## php-worker-pool

Namespace `PhpWorkerPool`. A Master process owns a pool of forked workers;
clients talk to it over a Unix domain socket.

```php
use PhpWorkerPool\Master\Master;
use PhpWorkerPool\Protocol\Request;
use PhpWorkerPool\Protocol\Response;
use PhpWorkerPool\Sdk\WorkerPoolClient;

// server side
$master = new Master(
    socketPath: '/tmp/php-worker-pool.sock',
    minWorkers: 2, maxWorkers: 16, maxQueueSize: 10_000,
    requestTimeoutSeconds: 30.0,
    handler: static fn (Request $request): Response => match ($request->action) {
        'calculate' => Response::of(...),
        default => Response::error('unknown_action'),
    },
    bootstrap: static function (Logger $logger): void { /* per-worker warm-up */ },
);
$master->run();   // SIGTERM/SIGINT/SIGCHLD/SIGHUP/SIGUSR1 handled internally

// client side
$client = new WorkerPoolClient('/tmp/php-worker-pool.sock', timeoutSeconds: 5.0);
$result = $client->call(new Request('calculate', ['a' => 10, 'b' => 20]));

// parallel fan-out: several sends, awaited together (workers run concurrently)
$pending = [$client->send(new Request('x', [])), $client->send(new Request('y', []))];
[$x, $y] = $client->all(...$pending);            // all-or-fail
$answers = $client->allWithin(2.0, ...$pending); // degrade, keep positions
```

Key facts:

- The handler runs **inside each forked worker**; `bootstrap` must *create*
  resources per worker (never capture a shared connection through `use`).
- Errors surface to the client as `ServerErrorException`; timeouts as
  `RequestTimedOutException`; an overloaded pool answers `server_overloaded`.
- The Master applies backpressure by queueing up to `maxQueueSize` and
  answering `server_overloaded` past it — the platform's `WorkerManager` and
  `ConcurrentTaskRunner` sit on top of this SDK.

## Adapter mapping (used by later phases)

| Platform class                 | Wraps                                  |
| ------------------------------ | -------------------------------------- |
| `Application\Application`      | `RequestHandler` boundary of the server |
| `Http\Request/Response/Router` | platform-internal values + routing; adapts at the boundary |
| `Storage\Database`             | `Connection`/`ConnectionPool`          |
| `Storage\Repositories\*`       | `Database` + `ResultSet`               |
| `Cache\CacheService`           | `CacheClient`                          |
| `Queue\JobDispatcher`          | `Producer` + `Queue`                   |
| `Queue\JobConsumer`            | `WorkerPool` + `JobDispatcher`         |
| `Workers\WorkerManager`        | `WorkerPoolClient`                     |
| `Workers\ConcurrentTaskRunner` | `WorkerPoolClient` fan-out (`send`/`allWithin`) |