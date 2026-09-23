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
| php-mini-database             | `bin/minidb.php start` (platform entry) | `127.0.0.1:5433`   |
| php-mini-cache                | `bin/cache.php` (platform entry)      | `127.0.0.1:6380`    |
| php-job-queue                 | none (in-process)                 | -                   |
| php-worker-pool               | `bin/worker.php` (platform entry)     | `/tmp/php-worker-pool.sock` |

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

The component's own `bin/minidb-server` boots with
`require __DIR__.'/../vendor/autoload.php'`, which only resolves while the
component *is* the root project; as a Composer dependency the path points at
the component's own vendor directory and does not exist. The platform runs
the server through its own one-file entry `bin/minidb.php`, which loads the
platform autoloader and calls `PhpMiniDatabase\Cli\ServerApplication` with the
same arguments the component's bin script would receive.

`PlatformCli::serve()` owns the server process: it spawns it daemonized
(`start --host --port --data --daemon --pid-file --log-file`), waits for the
TCP port to answer, applies the schema through `Storage\Migrator`, and stops
the server on shutdown only if this `serve` started it (a server that was
already running on the same data directory is reused and left up). Data
survives serve restarts in the data directory from `config/platform.php`.

The mini database has no auto-increment. Order ids are UUID v7 (`ramsey/uuid`,
time-ordered like a ULID), generated in `OrderService`; `orders.id` is
`VARCHAR(36)`.

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

The component's own `bin/server.php` has the same `require
__DIR__.'/../vendor/autoload.php'` flaw as the database's — it only resolves
while the component *is* the root project. The platform runs the server
through its own one-file entry `bin/cache.php`, which boots the platform
autoloader and runs `PhpMiniCache\Server\CacheServer` with the component's
own environment contract (`CACHE_HOST`, `CACHE_PORT`, plus a `CACHE_SNAPSHOT`
path that enables persistence).

The cache component has **no daemon mode**: its server is a foreground
process. `PlatformCli::serve()` therefore owns the cache as a direct child —
it spawns `bin/cache.php` with stdout/stderr redirected into the cache data
directory, waits for the TCP port to answer, and SIGTERMs it on shutdown only
if this `serve` started it (an already-answering server is reused and left up,
probed with a short-timeout `CacheClient::ping()`). SIGTERM is the cache's
graceful shutdown: it stops accepting, drains, writes a final snapshot, and
exits. The snapshot is loaded on boot and rewritten periodically, so a
planned restart keeps the entries written since the last snapshot — same
durability story as the database.

The read path is **cache-first since the cache phase, with the database as
the source of truth and the cache as derived state**: `OrderReadHandler`
looks up `order:{id}` first — a hit answers with the cached representation
and never touches the repository, a miss reads the authoritative row, refills
the cache (TTL 60s), and answers (`X-Cache: hit|miss` on every response). A
cache that cannot answer is a bypass, not a failure: the request is still
served from the database. `Cache\CacheCounters` records `hits`, `misses`,
`sets`, `deletes` and `bypasses` next to the read path.

The consistency contract on writes (no distributed protocol — the trade-off
is made explicit instead):

- `POST /orders` is **populate-on-write** (`CacheService::setOrder`, `cache.set`):
  a fresh UUID can never collide with an existing entry, so nothing is stale —
  the authoritative row is written through and the very first read is a hit.
- `PUT /orders/{id}` is **invalidate-on-write** (`CacheService::deleteOrder`,
  `cache.delete`): the row changed, so any cached copy is stale and is removed;
  the next read misses, re-reads the authoritative row, and refills. Before
  this phase the stale entry would have been served until its TTL.
- Both are best-effort: a cache that cannot answer on the write path counts a
  bypass and the write still succeeds.

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

Namespace `PhpJobQueue`. Producer and consumer are separate platform
processes; the append-only journal is the only shared state between them.

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
  workers/busyWorkers/deadLettered — the component's live view of one
  consumer. The platform's own `queue:status` and `GET /queue/status`
  (below) are journal-derived instead, so any process can read them without
  owning the consumer.
- `JobPriority`, `JobResult`, `DeadLetterQueue`, `JobStorage`,
  `Scheduler\DelayedJobScheduler` exist for the later reliability phases.

### Platform queue lifecycle (Step 9, shipped)

The queue is split across processes, with the append-only journal
(`queue/queue.log`, one JSON record per state change, last write per job
wins) as the source of truth:

```text
serve (HTTP) ──producer──► journal ◄──consumer──► WorkerPool ──► JobExecutor
   │                              ▲                                    │
   └──────── GET /queue/status ───┘                 JobRegistry → OrderCreatedJob
```

- `serve` wires an `InMemoryQueue` over a `FileStorage` journal and a
  `Producer`, and hands the producer to `OrderService` as an optional seam.
  A POST runs **write database → enqueue `order.created` → respond**.
- `queue:publish <type> [payload-json]` is the same producer in a CLI: it
  appends one job to the same journal the consumer reads.
- `queue:consume` restores the journal (`InMemoryQueue::restoreFromStorage()`),
  then runs a `QueueConsumer` loop: the component's tick (dispatch pending →
  apply answers → requeue expired) plus a journal re-sync every 100ms, so a
  job published by another process while the consumer lives is picked up
  without a restart. `Queue\JobExecutor` is the WorkerPool handler: it builds
  its own Database/CacheService per forked worker (nothing inherited from the
  parent), and `Queue\JobRegistry` maps a carrier's type string to the
  platform `Job` that runs it. SIGTERM/SIGINT stop the loop gracefully.
- `queue:status` / `GET /queue/status` read `Queue\QueueJournal` — the same
  replay the consumer restores from — and expose the metrics PLAN Step 9
  names: `queue.depth` (non-terminal jobs), `queue.published`, plus
  `queue.completed`, `queue.failed`, `queue.retried` (attempts > 1).

### Platform job model (Step 8, shipped)

The write → enqueue → respond seam (the lifecycle section above wires it):
the handlers never know a queue exists, and a service built without a
producer (tests, future read-only commands) stays a plain synchronous write
path. The platform separates the carrier from the behavior, the way every
phase does:

- `PhpJobQueue\Job\Job` — the component's carrier: type, payload, attempt
  bookkeeping. `Producer::dispatch()` creates it.
- `Queue\Job` — the platform's interface; `execute(JobContext)` is what a
  job DOES.
- `Queue\JobContext` — what a running job may reach: its own carrier plus
  the `OrderService` and `CacheService`.
- `Queue\Jobs\OrderCreatedJob` — the first job, type `order.created` with
  payload `['order_id' => ...]`. It re-reads the authoritative row from the
  database and warms the derived cache entry (idempotent; heals the window
  when the write path had to bypass a down cache). A missing/unknown order
  throws `RuntimeException` — a failed attempt for the next phase's
  retry/DLQ story, never a silent skip.

Recovery shape, stated up front: the journal keeps the last word on every
job, so a later consumer restores it and replays READY jobs (at-least-once);
`OrderCreatedJob` is idempotent by design, so a duplicate execution only
refreshes the cache.

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

## The controller example (shipped)

A concrete example of a CPU task split into parts and run side by side on
several workers, reached from the HTTP control plane:

`GET /parallel?work=200000&split=4` folds 200k sha256 iterations, split into
four chunks, one per worker. The handler sends every chunk before awaiting
any of them, so they genuinely run concurrently, then aggregates by slot; a
chunk that times out or is rejected degrades into a missing slot
(`allWithin`'s bargain) and is reported in `degraded` instead of failing the
request (206 with some, 503 when none complete, 400 on bad `work`/`split`).

The wiring, platform-side:

- `bin/worker.php` — the Master process. One file that loads the platform
  autoloader and runs `Master(minWorkers: 2, maxWorkers: 16, handler:
  WorkerTasks::handler())`. Like the cache, the pool has no daemon mode, so
  `PlatformCli::serve()` spawns it as a child when no pool answers a ping on
  the configured socket (`workerPoolAnswers()`), owns it, and SIGTERMs it on
  shutdown (`stopWorkerPoolIfOwned()`) — graceful: settle in-flight tasks,
  exit workers, remove the socket.
- `Workers\WorkerTasks` — the only code a worker runs: `ping` and `hash_chunk`
  (a deterministic sha256 chain reporting its `iterations`, elapsed
  `microseconds` and `checksum`). A payload that cannot describe a task is
  answered with `Response::error('bad_params')`, not a thrown exception, so
  the controller sees a degraded chunk instead of a crashed worker.
- `Workers\ConcurrentTaskRunner` — the fan-out over one `WorkerPoolClient`
  connection: `run()` is all-or-fail (`all`), `runWithin($seconds, ...)`
  shares one budget over the group and returns whatever answers arrived,
  keyed by position.
- `Application\Handlers\ParallelHandler` — the controller above, with the
  runner injected from `serve()`; without one it answers 503 "not configured".

The observable proof of concurrency: four 50k chunks each report ~50ms of
worker time while the request's `wallMicroseconds` is also ~50-60ms — the
chunks overlapped instead of stacking into ~200ms.

## Adapter mapping (used by later phases)

| Platform class                 | Wraps                                  |
| ------------------------------ | -------------------------------------- |
| `Application\Application`      | `RequestHandler` boundary of the server |
| `Http\Request/Response/Router` | platform-internal values + routing; adapts at the boundary |
| `Storage\Database`             | `Connection`/`ConnectionPool`          |
| `Storage\Repositories\*`       | `Database` + `ResultSet`               |
| `Cache\CacheService`           | `CacheClient`                          |
| `Queue\Job`                    | platform interface; runs a `PhpJobQueue\Job\Job` via `JobContext` |
| `Queue\JobContext`             | carrier + `OrderService` + `CacheService` for one execution |
| `Queue\Jobs\OrderCreatedJob`   | executes an `order.created` carrier     |
| `Queue\JobRegistry`            | maps a carrier type to the platform `Job` that runs it |
| `Queue\JobExecutor`            | WorkerPool handler; builds per-worker services, runs registry |
| `Queue\QueueConsumer`          | `JobDispatcher` loop + journal re-sync (cross-process handoff) |
| `Queue\QueueJournal`           | `FileStorage` replay → `queue.*` counters |
| `Application\Handlers\QueueStatusHandler` | `QueueJournal` over HTTP (`GET /queue/status`) |
| `Workers\WorkerManager`        | `WorkerPoolClient`                     |
| `Workers\ConcurrentTaskRunner` | `WorkerPoolClient` fan-out (`send`/`allWithin`) |
| `Workers\WorkerTasks`          | per-worker task handler (`ping`, `hash_chunk`) |