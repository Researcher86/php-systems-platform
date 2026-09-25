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

`Storage\Migrator` owns the schema: `orders` plus the read-only reference
catalog the concurrency phase loads — `customers(name, tier, since)`,
`products(sku, title, price)`, `inventory(sku, available, reserved)` — which
it also seeds (there is no upsert, so a seed row is a read followed by an
insert when missing). `orders.product` is the sku an order names. A data directory written before
that column existed is brought forward in place: `ALTER TABLE orders ADD
COLUMN product VARCHAR(64)` (added columns are nullable, since rows already
exist), then `UPDATE orders SET product = ? WHERE product IS NULL` backfills
the default sku. On every later start the server answers `Table "orders"
already has a column "product"` — the one failure the migration treats as
"already applied".

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
- `queue:publish <type> [payload-json] [key]` is the same producer in a CLI:
  it appends one job to the same journal the consumer reads; the optional
  key (Step 20) travels with it, so a manually published `order.process`
  deduplicates across redeliveries exactly like one published by the write
  path.
- `queue:consume` restores the journal (`InMemoryQueue::restoreFromStorage()`),
  then runs a `QueueConsumer` loop: the component's tick (dispatch pending →
  apply answers → requeue expired) plus a journal re-sync every 100ms, so a
  job published by another process while the consumer lives is picked up
  without a restart. Each of the consumer's php-job-queue workers is a
  **forwarder** that hands its job to the php-worker-pool via
  `Workers\WorkerManager`; the pool's forked workers (their `WorkerJobs`
  handler → `Queue\JobExecutor` → `JobRegistry`) are what actually execute
  it, with per-worker services built inside each fork. This is PLAN Step 10's
  Queue Consumer → Worker Manager → Workers. SIGTERM/SIGINT stop the loop
  gracefully.
- `queue:status` / `GET /queue/status` read `Queue\QueueJournal` — the same
  replay the consumer restores from — and expose the metrics PLAN Step 9
  names: `queue.depth` (non-terminal jobs), `queue.published`, plus
  `queue.completed`, `queue.failed`, `queue.retried` (attempts > 1).
- `benchmark <jobs> <workers>` (PLAN Step 12) runs a measured workload over
  the real pipeline: it publishes `jobs` `bench.noop` jobs
  (`Queue\Jobs\NoopJob` — deliberately database-free, so the measurement is
  the queue/pool path, not the storage layer) into an isolated journal,
  drives `Queue\QueueConsumer` against an isolated fixed-size pool, and
  reports total time, throughput, average/p95 latency, queue depth and
  worker utilization. Completion is credited by `WorkerRegistry` the moment
  an answer lands. Running 100/4, 1000/4 and 1000/8 shows that doubling the
  workers does not halve the time.

### Graceful shutdown (Step 21, shipped)

`worker` ("start the worker pool and queue consumer") is the production
shape of one worker node: the consumer it runs forwards to the pool Master
(`bin/worker.php`) it owns as a child, so one process anchors the whole
shutdown sequence and both sides of it drain in order. `queue:consume` is
the same run for an operator who brings the pool themselves.

SIGTERM/SIGINT are handled anywhere a long-running platform process lives:
`serve` stops its `Server` + loop then releases the cache/pool/database
children it owns, the `Master` (php-worker-pool) closes its socket, absorbs
what clients already sent, drains pending requests inside its own shutdown
budget, tells stragglers, and exits its workers, and the consumer follows
`QueueRuntime`'s contract — the loop notices a stop flag on its next pass.

The consumer shutdown sequence (what `PlatformCli::queueConsume()` prints
on the way out) is the PLAN Step 21 sequence end to end:

```text
receive signal
      ↓
stop accepting new work          ← QueueConsumer loop exit (flag, one-shot)
      ↓
stop pulling new jobs            ← loop stops re-syncing the journal
      ↓
finish currently executing jobs  ← JobDispatcher::shutdown(grace 10s)
      ↓
drain workers                    ← idle forwarders out, busy left alone
      ↓
stop workers                     ← forwarder pool stopped, then the owned
                                    pool Master gets SIGTERM → its own drain
      ↓
close resources                  ← registry snapshot, database, servers
      ↓
verify + exit                    ← exit 0 only if no job was silently lost
```

The verify step is PLAN's "Verify that jobs are not silently lost": the
journal is append-only and replayed on restart, so after the drain every
row must be either terminal (`COMPLETED`/`FAILED`) or recoverable
(`READY`/`PROCESSING`/`DELAYED`) — nothing may sit in between. The tail
prints `terminal=N recoverable=M lost=0` and the command exits non-zero
when the invariant breaks; `testGracefulShutdownLosesNoJobs` in
`ServeIntegrationTest` pins it by SIGTERMing a live consumer and draining
the survivors with a fresh one.

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
  the `OrderService` and `CacheService` — and, Step 20, an optional
  `IdempotencyGuard` and `InventoryRepository` write side, both null when
  they are not wired so nothing that predates the step changes behavior.
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
  autoloader and runs `Master(minWorkers: 2, maxWorkers: 16, ...)` with a
  handler that routes two task families: `ping`/`hash_chunk` to
  `WorkerTasks`, and `job.execute` to `WorkerJobs`. Like the cache, the pool
  has no daemon mode, so `PlatformCli::serve()` spawns it as a child when no
  pool answers a ping on the configured socket (`workerPoolAnswers()`), owns
  it, and SIGTERMs it on shutdown (`stopWorkerPoolIfOwned()`) — graceful:
  settle in-flight tasks, exit workers, remove the socket. `queue:consume`
  uses the same ensure/own/stop helpers when it runs standalone.
- `Workers\WorkerTasks` — the hash tasks: `ping` and `hash_chunk`
  (a deterministic sha256 chain reporting its `iterations`, elapsed
  `microseconds` and `checksum`). A payload that cannot describe a task is
  answered with `Response::error('bad_params')`, not a thrown exception, so
  the controller sees a degraded chunk instead of a crashed worker.
- `Workers\WorkerJobs` — the queue task: `job.execute` takes a job's plain
  `toArray()` array, rebuilds the carrier (`Job::fromArray()`), and runs it
  through `Queue\JobExecutor` (per-worker services → `JobRegistry`). A
  failure is answered `Response::error('job_failed', ...)` — the pool sees an
  application outcome, not a crashed worker, and the queue retries it.
- `Workers\WorkerManager` — the queue side of that bridge, and the Worker
  Manager of PLAN Step 10: it sends a `Job` to the pool as a `job.execute`
  task over one `WorkerPoolClient`. A `job_failed` answer becomes a
  `RuntimeException` (a failed attempt for the queue); a dead pool propagates
  the connection error the same way. `queue:consume`'s php-job-queue workers
  are forwarders that run each job through it.
- `Workers\WorkerRegistry` — the queue consumer's own worker lifecycle made
  observable (PLAN Step 11). php-worker-pool keeps its workers' states
  private inside the Master with no client channel, so the lifecycle the
  platform can truthfully read is its own: each forwarder's `id`, `pid`,
  `state` (STARTING/IDLE/BUSY/DRAINING/STOPPING/DEAD), current job,
  `started_at`, and — once php-job-queue's own `Worker` grew the getters —
  `tasks_completed`/`tasks_failed` too, all read straight off the
  php-job-queue Worker objects the consumer owns. The journal is still
  replayed for what those counters can't answer: `capture()` runs between
  dispatch and collect (the only instant a worker is BUSY with a known
  job) and `settle()` reads each in-flight job's terminal state back out
  of the journal, for `resolvedAt()`/`resolvedCount()` — per-job
  completion timing `QueueBenchmark` needs, which an aggregate counter
  can't provide. The consumer writes a `workers.status.json` snapshot on a
  schedule, read by `workers:status` and `GET /workers`.
- `Workers\ConcurrentTaskRunner` — the fan-out over one `WorkerPoolClient`
  connection: `run()` is all-or-fail (`all`), `runWithin($seconds, ...)`
  shares one budget over the group and returns whatever answers arrived,
  keyed by position.
- `Workers\CatalogTasks` — the reference-data tasks `catalog.customer`,
  `catalog.product`, `catalog.stock`: one keyed read each, on the worker's
  own lazily built connection. `delay_ms` simulates an enrichment slower than
  a local table; a request without its key, or with an out-of-range delay, is
  `Response::error('bad_params')`.
- `Application\Handlers\ParallelHandler` — the controller above, with the
  runner injected from `serve()`; without one it answers 503 "not configured".

The observable proof of concurrency: four 50k chunks each report ~50ms of
worker time while the request's `wallMicroseconds` is also ~50-60ms — the
chunks overlapped instead of stacking into ~200ms.

### Concurrent database work (Step 13, shipped)

The same picture for I/O instead of CPU. One order's snapshot is the order
plus three pieces of reference data; the order read is a genuine dependency
(the other three are keyed by what it says), the three that follow are
independent of each other.

- `Domain\OrderLoader` — the seam: `load(string $id): ?OrderSnapshot`.
- `Domain\SequentialOrderLoader` — the three reads one after another, in one
  process. Used by `Queue\JobExecutor`, so a job running inside a pool worker
  does its own reads instead of competing for the pool it occupies.
- `Workers\ConcurrentOrderLoader` — the same three as one fan-out through
  `ConcurrentTaskRunner::run()` (all-or-fail: a silently missing part would
  be indistinguishable from an absent catalog row).
- `Queue\Jobs\OrderProcessJob` (`order.process`) — loads the snapshot and
  settles the order from it: stock the catalog can cover completes it,
  anything else cancels it, then the stale cached copy is dropped.
- `Workers\OrderLoadBenchmark` + `orders:compare <rounds> <delay-ms>` — the
  measurement: every loader given, the first as baseline, one untimed warm-up
  each, printed for both local reads and a simulated external dependency per
  part.

### php-concurrency (Step 14, shipped)

`php-concurrency` is a 38-lesson course, not a package — there is no
`composer.json` to require. It is integrated the only way a course can be:
the platform implements one path with the primitives itself, instead of
letting php-worker-pool own every fork.

`Workers\ForkedOrderLoader` is that path — the same `OrderLoader` contract as
the other two, built from `stream_socket_pair()` before the fork,
`pcntl_fork()`, closing the end each process does not own (or the parent's
reads never see EOF), and `pcntl_waitpid()` for every child. Each child
resets the signal handlers it inherited (`serve` and `queue:consume` install
handlers over their own state) and opens its own database connection — a
forked socket answered by two processes is how a protocol dies. A child that
throws answers with its message and exit code 1, so a failed part is a failed
load rather than a silently empty one.

Measured (5 rounds, container), the same snapshot by all three models:

| model | local reads | 50ms simulated dependency per part |
| --- | --- | --- |
| sequential | 0.713 ms (baseline) | 161.200 ms (baseline) |
| forked | 7.507 ms (0.09x) | 65.568 ms (2.46x) |
| pooled | 0.973 ms (0.73x) | 54.898 ms (2.94x) |

Which is the argument for the pool, made with numbers: forking overlaps the
waiting just as well, but pays a process and a connection on every load,
while the pool paid them once. The database server is a single event loop, so
what overlaps is *waiting* — concurrency does not multiply database
throughput.

### php-memory-lab (Step 15, shipped)

`php-memory-lab` is a 32-experiment lab (`composer.json` name
`researcher86/php-memory-lab`, `type: project`) measuring PHP's process and
memory model — not a package meant to be required, and PLAN Step 15 says so
explicitly ("expose memory behavior rather than turning php-memory-lab into a
runtime dependency"). The platform re-implements the one reader its demo
needs instead of the lab's whole measurement stack.

- `Memory\ProcStatusReader` — parses `/proc/<pid>/status` (`Key: value` lines,
  `N kB` converted to bytes), the same shape and conversion as the lab's own
  reader.
- `Memory\MemoryReporter` — `snapshot()` joins `memory_get_usage()` /
  `memory_get_usage(true)` (the PHP view) with `VmRSS`/`RssAnon`/`RssShmem`
  (the OS view); `diff()` turns two snapshots into signed deltas. A failed
  `/proc` read degrades OS fields to `null` rather than throwing.
- `Memory\ForkedMemoryDemo` — the demo itself: allocates an array, then the
  same fork + `socketpair()` + `pcntl_waitpid()` idiom as
  `Workers\ForkedOrderLoader` (Step 14), except one child reporting two
  snapshots of itself (right after `fork()`, and after writing to the
  inherited array) instead of several children each reporting one read.
- `memory:demo` CLI command — prints all three snapshots with running
  deltas. Needs no platform infrastructure, so it is safe to run standalone.

Measured (container): the child's private memory (`RssAnon`) does not move at
all immediately after `fork()` — still the parent's pages, shared read-only —
then grows by almost exactly the array's size once the child writes to it.
That growth is copy-on-write, made visible instead of asserted.

### Worker memory (Step 16, shipped)

The same question, asked of a real pool instead of one throwaway child: does
copy-on-write still pay off once workers are long-lived processes doing
independent work? php-worker-pool's own worker telemetry
(`Worker\Telemetry\SharedTelemetry`/`ShmWorkerMemory`) is internal to the
Master — it feeds the recycling policy, with no wire action that reads a
worker's memory back to a client — so the platform added one task instead of
reaching into that internal state.

- `Workers\WorkerMemoryTasks` — the pool-side task, `memory.hold`: a worker
  snapshots itself, allocates and *retains* (`$held`, an instance property
  that survives for the worker's whole life because the pool keeps this same
  handler instance alive across every request it answers) an array of the
  given size, snapshots again, and answers both plus its own pid — the only
  way to tell one worker's numbers from another's, since the wire protocol
  carries none. Routed in `bin/worker.php` by a `memory.` prefix, alongside
  `catalog.` and `job.execute`.
- `Workers\WorkerMemoryBenchmark` — split in two on purpose. `run()` fans
  `$workers` `memory.hold` requests out over one `ConcurrentTaskRunner` (all
  in flight before any is awaited, so N idle workers each pick up one) and
  needs a real pool; `aggregate()` is the arithmetic alone, given the answers
  as plain arrays, which `WorkerMemoryBenchmarkTest` pins down with fixed
  numbers instead of a live process.
- `workers:memory [elements]` — for each of 1, 2, 4, 8: a fresh isolated pool
  (`WORKER_POOL_MIN`/`MAX` on its own socket, the same override the queue
  benchmark uses), every worker holding `elements` ints at once, summed
  against the Master's own RSS (`Memory\ProcStatusReader` again, this time
  against the pool process's pid rather than the current one).

Measured (container, 100,000 elements/worker): going from 1 to 8 workers grew
total RSS by 117M before any worker wrote to its array and by 135M once each
held its own copy — 18M more, matching 7 private copies of a ~2.5MB array.
RSS summed across processes double-counts pages every worker still shares
with its parent, which is why the *delta* between the two totals — not
either alone — is the number that isolates what writing actually cost.

**Not superseded by php-worker-pool's own `_stats` admin action, once that
landed upstream.** `_stats` answers `memoryBytes` per worker from the
Master's `ShmWorkerMemory` telemetry — but only when `maxMemoryBytes` is
configured, which `bin/worker.php` never does, so it would read `null` for
every worker in this platform's own deployment. Even configured, it is a
passive read of whatever the pool already tracked, not a way to make a
worker *do* anything — `workers:memory` needs the before/after readings
around a real allocation `memory.hold` triggers, which nothing about
`_stats` can substitute for. `Workers\WorkerRegistry` is not a candidate
for it either: `_stats` describes php-worker-pool's own Master pool, and
`WorkerRegistry` observes a different one entirely - `queue:consume`'s
php-job-queue forwarders (see Step 11 above).

### Backpressure (Step 17, shipped)

Neither `Queue`'s `InMemoryQueue::push()` nor `Producer::dispatch()` enforces
a capacity — the component queue is unbounded by design, so `MAX_QUEUE_SIZE`
and what happens at it are entirely the platform's to decide.

- `Queue\BackpressurePolicy` — `evaluate(): BackpressureDecision` reads
  `QueueJournal::snapshot()['depth']` (the same durable, cross-process count
  `GET /queue/status` answers with — never an in-memory counter, since the
  HTTP process's own queue handle only ever grows) against a configured
  `maxSize`.
- `Application\Handlers\OrderCreateHandler` — checks the policy first, before
  decoding the body or touching the database: at capacity, **reject** — `429`,
  `Retry-After: 1`, `{queueDepth, queueMaxSize}`, nothing written, nothing
  enqueued. Not block (the component's HTTP server is single-connection at a
  time; blocking it on a queue it cannot itself drain would stall every other
  request) and not a silent drop (a `201` for work that was thrown away would
  lie to the caller). The policy is optional (`?BackpressurePolicy = null`) —
  wired in `PlatformCli::application()` only when both a queue log and a
  configured `max_size` exist.
- `config/platform.php`'s `queue.max_size` (default 500) is the limit;
  nothing else in the platform reads or writes it.

### Timeouts (Step 18, shipped)

Every important boundary got an explicit, config-named timeout in this
phase — two of them were previously silent bugs, found while auditing the
rest and fixed here rather than deferred.

**HTTP request timeout — a real fix.** `ServerConfig`'s `connectionTimeout`/
`headerTimeout` were always built from config, but nothing ever called the
component's own `Server::closeIdleConnections()` /
`closeSlowHeaderReads()` — the sweep that acts on those numbers. A
connection that sent nothing at all stayed open indefinitely.
`PlatformCli::serve()` now registers `$loop->every(1.0, ...)` calling both,
sourced from `http.request_timeout` (idle) and the new `http.header_timeout`
(Slowloris — a trickling client stays alive under the idle check because it
IS sending bytes, just never enough to finish a header block).
`ServeIntegrationTest::testAConnectionThatSendsNothingIsClosedAfterTheRequestTimeout`
opens a raw socket, sends nothing, and asserts the server closes it — this
would time out (not EOF) against the pre-fix code.

**Worker timeouts — also a real fix.** `bin/worker.php`'s `Master` never set
`workerExecutionTimeoutSeconds`, `workerBootstrapTimeoutSeconds` or
`workerDepartureTimeoutSeconds` — all three ran on the component's own
defaults (60s / 30s / 10s), unconfigured and invisible. All three are named
in `config/platform.php`'s `workers` block now (`execution_timeout` /
`bootstrap_timeout` / `departure_timeout`), and `execution_timeout` takes an
env override (`WORKER_POOL_EXECUTION_TIMEOUT`, alongside the existing
`WORKER_POOL_MIN`/`MAX`/`TIMEOUT`) so a test can demonstrate it on a short
deadline. This is `php-worker-pool`'s own timeout model
(`WorkerPool::terminateStuckWorkers()`'s docblock spells out the same
three-way split PLAN Step 18 asks for): **execution timeout** kills and
replaces a worker holding one task too long (a handler that will never
return), **lifecycle timeout** (bootstrap/departure) bounds a worker that
fails to start or fails to leave, with no task in sight either way. Neither
is the **request timeout** (`workers.task_timeout`, the existing
`WorkerPoolClient`/`Master` round-trip wait) — that one is the *caller*
giving up; execution timeout is the *pool* taking its slot back, and is set
comfortably above the request timeout so that by the time it fires, whoever
was waiting has already been answered.
`ServeIntegrationTest::testAWorkerStuckPastItsExecutionTimeoutIsKilledAndReplaced`
spins up a 1-worker pool with a 1s execution timeout, sends a request that
sleeps 30s and never awaits it, then proves a *second* request still answers
promptly — only possible if the stuck worker was actually replaced, not
merely waited out. `Workers\WorkerTasks::sleep` (`sleep` action, `ms` param)
is the task that made this demonstrable: it holds a worker busy for exactly
as long as asked and nothing else.

**Database operation timeout.** `read_timeout`/`write_timeout` join the
existing `database.timeout` (connect) in config, matching the component's own
`ClientConfig` defaults exactly (30s each) — naming them changes no runtime
behavior, it only makes the number visible. `Storage\Database::connect(array
$config)` / `Database::configFrom(array $config): ClientConfig` centralize
the mapping that seven call sites used to repeat by hand
(`PlatformCli` ×4, `JobExecutor`, `ForkedOrderLoader`, `CatalogTasks`) — a
consolidation this phase's config additions made worth doing, tested directly
(`DatabaseConfigTest`) without a database connection.

**Queue operation timeout** is visibility, and needed no new code: it is
*already* explicit, and deliberately *derived* from `workers.task_timeout`
rather than independently configured (`QueueConsumer`'s wiring — see the
Queue section above) — a job may legitimately sit in a worker's hands for up
to one full round trip, and visibility has to span that or the queue would
reclaim jobs that are still being worked on.

### Retry policy (Step 19, shipped; revised once the component grew a hook)

`JobDispatcher::handleFailure()` — read from the component's own source, not
guessed — decides retry-or-fail with `attempts < maxAttempts` AND (once
this landed upstream) an optional `?Closure(Job, Throwable): bool
$shouldRetry` hook, consulted first: a `false` from it means never retry,
regardless of attempts remaining.

- `Queue\ValidatesPayload` — a job type opts in with a static
  `validate(array $payload): ?string`, checked from the payload alone, no
  I/O. `OrderCreatedJob`/`OrderProcessJob` both implement it (missing
  `order_id` → rejected); `NoopJob` does not (nothing to validate).
- `JobRegistry::shouldRetry(): Closure` — the hook itself: refuses
  eligibility whenever `JobRegistry::validate()` already knows the
  payload can never work. Passed to every `JobDispatcher` construction
  (`queue:consume`, the benchmark's own consumer, `ServeIntegrationTest`'s
  `drainQueue()`). The job still gets ONE real dispatch — `execute()`
  validates again and throws — so the rejection happens at the natural
  point (after a real failure) rather than pre-flight from a payload
  guess, but the *cost* is unchanged: one attempt consumed, never three.
  **Superseded by this: `Queue\ValidatingQueue`**, a `Queue` decorator
  that used to intercept `pop()` and reject a job before it was ever
  dispatched, built solely because the component had no per-error retry
  hook at all. Removed once one existed upstream.
- **What stays on the normal retry path, deliberately**: "order not found"
  for either job. Answering it needs a database read a payload check
  cannot do, and in this platform's write-before-publish design it is
  already unreachable in normal operation — a real, if rare, condition
  rather than a provably permanent one, which is exactly the distinction
  `ValidatesPayload` is *for* drawing.
- **Job metadata (`started_at`/`completed_at`/`last_error`) — now the
  component's own.** `PhpJobQueue\Job\Job` carries all three directly,
  stamped by `JobDispatcher` around every dispatch and outcome; persisted
  the same way the rest of a job's state already was, through the same
  `JobStorage`. **Superseded by this: `Queue\JobAttemptJournal`**, the
  platform's own append-only per-attempt log, written by `JobExecutor`
  around a try/finally — built because `Job::toArray()` used to have
  none of these three fields. Removed once the component tracked them
  itself. The one thing lost along with it: the component tracks the
  LATEST attempt only, not a full history of every one, so `queue:job`
  now shows one attempt's timing and error, not a per-retry table.
- `queue:job <id>` CLI — reads `QueueJournal`'s row (id/type/state/
  attempts/maxAttempts/createdAt/startedAt/completedAt/lastError)
  straight off the same journal everything else in this platform already
  reads — no second file to join anymore.

### Idempotency guard (Step 20, shipped)

An at-least-once queue cannot tell "the worker died before the work" from
"the work happened and the worker died before saying so" — a PROCESSING job
returns to READY on restart, so a job whose side effect ran once runs it
again. The platform's own non-idempotent side effect is Step 20's settle:
`OrderProcessJob` takes one unit off the shelf. The piece that deduplicates
redeliveries is the component's, reused as-is:

- **`jobs.idempotency_store`** (`config/platform.php`) — the path of a
  second append-only JSONL `FileStorage` journal, next to the queue
  journal. Executors build one shared `IdempotencyGuard` over it (lazily,
  per fork, exactly like the connections), so the set of "already done"
  operations survives a restart by replay: a fresh worker reads the store
  the dead one wrote.
- **The key names the operation, not the delivery.** The write path
  dispatches `order.created:<order id>`; the demo and the manual
  `queue:publish ... [key]` path use `order.process:<order id>`. A job id
  changes when the job is recreated; the operation key does not.
- **Both platform jobs check-then-do-then-record, in that order.** A key
  the guard already knows returns before any read or write (order snapshot
  included); the record is written after every side effect, so the crash
  window is the honest one the component's `IdempotencyGuard` docblock
  describes — check, effect and record are three separate steps, and a
  crash between effect and record still double-applies. This is
  deduplication over at-least-once delivery, *not* exactly-once execution;
  closing that last window needs the side effect and its record to commit
  together, a property of the storage rather than the queue.
- The settle itself is a new write side, `Storage\Repositories\
  InventoryRepository::decrementAvailable()`, SQL-guarded (`available > 0`)
  and run when `OrderProcessJob` completes with stock. Reads stay on
  `CatalogRepository`. The seam is optional in the context: an executor
  without an inventory write side settles nothing, so every pre-Step-20
  test and command keeps its exact behavior.

Exercised three ways: `tests\Unit\IdempotencyGuardTest` (the record
survives a fresh guard over the same store; unrelated JSONL records are
not read as operations); `ServeIntegrationTest` (the write path keys its
`order.created` job; an unkeyed redelivery settles stock twice; a keyed
one settles once across a fresh executor; and the real journal → consumer
→ pool path, where the redelivered job completes on its one delivery);
and `idempotency:demo`, which prints the same two-deliveries-twice story
for a real order with and without a guard.

### Failure injection (Step 22, shipped)

Failure is a property worth turning on on purpose: a platform whose crash
path is only ever exercised by accidents has never seen it work. Step 22
gives the lab three coordinated injection points, all of them pinning one
of the step's two sequences:

- **`demo.failing` (`Queue\Jobs\FailingJob`)** — a registered job that
  throws on every delivery, and deliberately does NOT implement
  `ValidatesPayload`, so `shouldRetry()` lets it spend its whole attempts
  budget. The sequence it reproduces is `job fails → retry → failure →
  dead/failed state`: the dispatcher retries it `max_attempts` times and
  retires it into the terminal `FAILED` journal row.
- **`worker.crash` (`Workers\WorkerFailureTasks`)** — a pool task whose
  worker SIGKILLs itself mid-request, with no chance to answer or clean
  up: exactly what an OOM-kill looks like from the pool's side. The
  Master's SIGCHLD path is the only witness — `reapCrashedWorkers()` fails
  the request it held with `worker_crashed`, `reapDeadWorkers()` removes
  the dead pid and immediately forks a replacement. The sequence is
  `worker crashes → manager detects → worker removed → replacement
  started`.
- **`POST /debug/fail-worker`** — the step's example endpoint. Its handler
  (`Application\Handlers\FailWorkerHandler`) drives the crash through
  `Workers\WorkerFailureInjector`, which records every phase's evidence off
  `WorkerPoolClient::stats()` (a Master-answered read that costs no pool
  capacity): the `worker_crashed` error arrives, then the dead pid drops
  out of `stats()`, then a never-before-seen pid appears. `failure:demo`
  and the integration tests use exactly the same injector.

The one switch is the environment, per the step's own rule ("Failure
injection should only be enabled in development/demo mode"):
`config/platform.php` derives `failure_injection.enabled` from
`PLATFORM_ENV` (default `dev`; enabled for dev/demo/test, disabled
otherwise). A production `serve` has no `/debug/fail-worker` route at all
(404), and a production pool answers `failure_injection_disabled` to a
`worker.crash` instead of dying — both verified (the disabled pool keeps
its two workers across such a request) rather than asserted.

Exercised by `tests/Integration/ServeIntegrationTest` (crash sequence on an
isolated two-worker pool; the disabled pool refusing to crash; a real
`demo.failing` job drained to `FAILED` at exactly `max_attempts`; the HTTP
endpoint crashing and replacing a real shared-pool worker) and
`tests/Integration/FailureDemoCommandTest`, which runs `failure:demo` end
to end as a subprocess.

### Observability (Step 23, shipped)

Step 23's metric set is one common model read from two halves. The first
is `Observability\MetricsRegistry`, the in-process accumulator every
component reports into with the standard names (`MetricsRegistry::*`
constants — the vocabulary is readable in one place, a typo is a fatal
error, a name is a contract): counters only go up, gauges keep only their
latest value, durations keep sum+count and read back as their average.
It is wired null-tolerantly, so components behave as before without one:
`Application` records `http.requests`/`http.errors`/`http.request_duration`
around each handled request, `CacheService` the `cache.hit`/`cache.miss`/
`cache.operations` on its read/write path, `Database` the
`db.operations`/`db.errors`/`db.operation_duration` around each query.

The second half is `Observability\MetricsReporter`, the single reader that
turns the registry plus the live sources into one snapshot: the queue
journal answers `queue.*` the way `queue:status` reads it, the worker pool
Master answers `workers.active`/`busy`/`idle`/`failed`, `worker.task_duration`
(Σ working seconds / handled requests) and `worker.rss` (average memory)
off `WorkerPoolClient::stats()`, and `process.rss` comes from
`MemoryReporter`. A pool that will not answer simply leaves its lines out
rather than failing the read.

Two surfaces expose the same dump, one `name value` per line, sorted:
`GET /metrics` (wired in `serve` the way every other route is; the
`Application\Handlers\MetricsHandler` says the route exists exactly when
serve hands `application()` a reporter) and the `platform.php metrics`
command, which needs no serve behind it. The dump format is plain text on
purpose — the contract is the metric names, not a server-specific
encoding.

Exercised by `tests/Unit/Observability/MetricsRegistryTest` (the model's
three kinds), `tests/Unit/ApplicationTest` (a request and a 404 flowing
into the registry), `tests/Integration/ServeIntegrationTest` (the
endpoint exposing every standard name, and exact deltas across a
create+read+404 round trip through HTTP, cache and database) and
`tests/Integration/MetricsCommandTest` (the CLI printing the journal-based
snapshot as a subprocess).

## Adapter mapping (used by later phases)

| Platform class                 | Wraps                                  |
| ------------------------------ | -------------------------------------- |
| `Application\Application`      | `RequestHandler` boundary of the server |
| `Http\Request/Response/Router` | platform-internal values + routing; adapts at the boundary |
| `Storage\Database`             | `Connection`/`ConnectionPool`          |
| `Storage\Repositories\*`       | `Database` + `ResultSet`               |
| `Cache\CacheService`           | `CacheClient`                          |
| `Queue\Job`                    | platform interface; runs a `PhpJobQueue\Job\Job` via `JobContext` |
| `Queue\JobContext`             | carrier + `OrderService` + `CacheService` (+ optional idempotency guard / inventory write side) for one execution |
| `Queue\Jobs\OrderCreatedJob`   | executes an `order.created` carrier     |
| `Queue\Jobs\NoopJob`           | `bench.noop` — the benchmark's database-free job |
| `Queue\JobRegistry`            | maps a carrier type to the platform `Job` that runs it |
| `Queue\JobExecutor`            | WorkerPool handler; builds per-worker services, runs registry |
| `Queue\QueueConsumer`          | `JobDispatcher` loop + journal re-sync (cross-process handoff) |
| `Queue\QueueJournal`           | `FileStorage` replay → `queue.*` counters |
| `Application\Handlers\QueueStatusHandler` | `QueueJournal` over HTTP (`GET /queue/status`) |
| `Workers\WorkerManager`        | `WorkerPoolClient` job execution (`job.execute`) |
| `Workers\WorkerJobs`           | pool-side `job.execute` handler → `Queue\JobExecutor` |
| `Workers\WorkerRegistry`       | forwarder lifecycle (pid/state, from php-job-queue `Worker` directly; per-job resolution from the journal) |
| `Workers\QueueBenchmark`       | measured queue → pool runs: publish, drive consumer, report metrics |
| `Application\Handlers\WorkersStatusHandler` | `WorkerRegistry` snapshot over HTTP (`GET /workers`) |
| `Workers\ConcurrentTaskRunner` | `WorkerPoolClient` fan-out (`send`/`allWithin`) |
| `Workers\WorkerTasks`          | per-worker task handler (`ping`, `hash_chunk`) |
| `Workers\CatalogTasks`         | per-worker reference reads (`catalog.customer/product/stock`) |
| `Storage\Repositories\CatalogRepository` | `Database` → customers / products / inventory |
| `Storage\Repositories\InventoryRepository` | `Database` → the settle write, `decrementAvailable` (Step 20) |
| `Domain\OrderLoader`           | platform interface; one order snapshot, three execution models |
| `Domain\SequentialOrderLoader` | the reads one after another, in this process |
| `Workers\ForkedOrderLoader`    | `pcntl_fork` + `stream_socket_pair` + `pcntl_waitpid` (no component) |
| `Workers\ConcurrentOrderLoader` | `ConcurrentTaskRunner` fan-out over `catalog.*` |
| `Queue\Jobs\OrderProcessJob`   | executes an `order.process` carrier from a snapshot |
| `Workers\OrderLoadBenchmark`   | the loaders side by side, baseline-relative |
| `Memory\ProcStatusReader`      | `/proc/<pid>/status` → bytes (no component) |
| `Memory\MemoryReporter`        | PHP counters + `ProcStatusReader` → snapshot/diff |
| `Memory\ForkedMemoryDemo`      | `pcntl_fork` + `stream_socket_pair` over a shared array (no component) |
| `Workers\WorkerMemoryTasks`    | pool-side `memory.hold` — snapshot, retain, snapshot |
| `Workers\WorkerMemoryBenchmark` | `ConcurrentTaskRunner` fan-out over `memory.hold` → aggregated report |
| `Queue\BackpressurePolicy`     | `QueueJournal` depth vs `max_size` → reject decision (no component) |
| `Storage\Database::connect/configFrom` | array config → component `ClientConfig`, one place instead of seven |
| `Workers\WorkerTasks::sleep`   | holds a worker busy for `ms` - the execution-timeout demo's task |
| `Queue\ValidatesPayload`       | job-type opt-in pre-flight check (no component) |
| `Queue\JobRegistry::shouldRetry()` | `JobDispatcher`'s `$shouldRetry` hook - refuses eligibility for a payload `validate()` already rejects |