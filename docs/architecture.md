# Architecture

This document is the *why* of the platform's shape: how the processes are
split, who owns what, and why each boundary is where it is. For how to run
and use it, see `README.md`; for component contracts, `components.md`.

## The split that shapes everything

The platform is four long-running processes, not one:

```text
serve            owns the HTTP server, the routes, the database server
                 (daemonized, pid-file owned), the cache server (child),
                 and the worker-pool Master (child)

queue:consume    owns the queue consumer; adopts the running pool Master
                 through WorkerManager and drains the journal

bin/worker.php   the worker-pool Master: forks worker processes, keeps
                 the pool at min..max, detects dead workers, replaces
                 them, and enforces the execution timeout

bin/minidb.php   the database server, daemonized and tracked by pid file
bin/cache.php    the cache server, a child of whoever started it
```

`serve` starts the pool because HTTP needs it (the `/parallel` route and the
queue's workers), but it does **not** consume the queue. `queue:consume`
adopts the already-running pool instead of starting a second one, so there is
one pool for both the HTTP fan-out and the queue. That split - producer in
one process, consumer in another, journal as the only shared state - is why
the queue is a durable file and not an in-memory list: two processes cannot
share a PHP array, but they can append to and read the same file.

## Process ownership

Everything owns what it started, and stops it on every path:

| Resource          | Owner        | How ownership is proven     |
| ----------------- | ------------ | --------------------------- |
| Database server   | `serve`      | `minidb.pid` pid file       |
| Cache server      | whoever started it | no pid file - a child handle, adopted if already running |
| Worker-pool Master| `serve` (or a command) | `bin/worker.php` child process |
| Worker processes  | the Master   | worker pids in `stats()`    |

The cache has no daemon mode, so it is a child of whoever started it. `serve`
only stops a cache it owns: an already-running cache is adopted, and the
adopter will not kill the adopter's infrastructure on the way out. This is
why a command that must kill the cache mid-run (the failure experiments) owns
the cache server itself.

## The serve wiring

`serve()` in `src/Cli/PlatformCli.php` is the whole application in one
place, assembled bottom-up so every dependency exists before the thing that
uses it:

1. One shared `MetricsRegistry` and one `Trace`, handed to the database and
   cache at construction, so both report into the same observability.
2. Database server ensured + migrated, cache server ensured (or skipped when
   `CACHE_ENABLED=0`), pool Master ensured.
3. Routes registered in `application()`: each later feature is a route added
   to the same router - health, order endpoints, cache-first reads, the
   producer seam, the backpressure policy in front of `POST /orders`, the
   `/metrics` and `/queue/status` and `/workers` observers, and (only in
   dev/demo) `POST /debug/fail-worker`. That function takes an
   `ApplicationWiring` rather than a dozen positional arguments, because a
   list of ten same-typed dependencies is one where a reordering compiles
   cleanly and hands every component the wrong object. Named wiring means the
   call site reads as the components' names, and a wrong one cannot compile.
4. The HTTP server on a select loop, with the idle/header timeouts enforced
   by a one-second sweep - otherwise the config numbers would just sit there.

Nothing in `serve` is conditional on a production flag except the failure
injection route: a production serve has no such route at all.

## Why not a framework

The components each own their mechanics; the platform wires them with the
thinnest possible adapter (see `components.md`). Every seam in `src/` is a
boundary that exists because two things had to talk - not because an
abstraction was fashionable. Where the platform adds behavior, it adds it as
a policy object with an explicit default (a null `BackpressurePolicy` is an
unchecked write path; a null `Producer` is a synchronous write), so a caller
with fewer pieces gets the old behavior back instead of a crash.

## Configuration

`config/platform.php` is a single file of defaults an environment variable or
a command may override. The environment overrides are the deliberate ones:
`QUEUE_MAX_SIZE`, `CACHE_ENABLED`, `PLATFORM_ENV`, `DATABASE_LATENCY_MS`,
`WORKER_POOL_*`. Two of them encode policy, not tuning:

- `PLATFORM_ENV` gates failure injection: worker crashes, `/debug/fail-worker`
  and the database delay exist only where a deployment is not serving.
- `CACHE_ENABLED=0` is a platform *without* a cache tier, which is a
  different thing from a cache that is down (see `cache.md`).

## One request, end to end

`POST /orders` goes: HTTP parser -> `OrderCreateHandler` -> backpressure
decision -> database write -> cache write-through -> enqueue `order.created`
-> 201. `GET /orders/{id}` goes: `OrderReadHandler` -> cache lookup -> hit, or
miss -> database -> cache refill -> `X-Cache: miss`. The queue worker side
re-opens the producing request's `request_id` trace scope from the job's
payload, so one logical request is one trace chain across processes (see
`observability.md`).

## Dependency boundaries

PLAN Step 34: dependencies should flow Application -> Interfaces ->
Infrastructure, and infrastructure must not become coupled to business
logic. The actual cross-namespace imports in `src/`:

```text
Application -> Http, Observability, Domain, Cache, Workers, Queue
Domain      -> Storage (repositories), Queue (job type constants)
Observability -> Queue (journal), Memory
Cache       -> Domain (Order), Observability
Queue       -> Storage, Domain, Cache, Workers, Observability
Storage     -> Domain (entities), Observability
Workers     -> Domain, Queue, Storage, Memory, Observability
Cli         -> everything (the composition root)
```

The directions the PLAN warns against, checked against the code:

- `Database -> HTTP` — absent. Storage imports nothing from `Http`.
- `Worker -> Controller` — absent. `Workers` imports nothing from
  `Application`.
- `Queue -> HTTP server` — absent. `Queue` imports nothing from `Http`.
- `Cache -> Domain` — present, and it is the one nuance to call out.
  `CacheService` imports the `Order` entity, but only to serialize it and to
  build its cache key (`order:<id>`). It never applies business rules to it -
  the cache cannot decide what an order *means*, only what a cached order
  *is* (its JSON). The rule's reason - infrastructure should not make
  business decisions - is intact; the coupling is to a serialized data shape,
  the same coupling a serializer or mapper has. Replacing it would push
  `json_encode` into four call sites and duplicate the one serialization
  concern the facade exists to own, so it is deliberately left and documented
  rather than hidden.

The rule in one line: **infrastructure knows the domain's data shapes and
speaks the domain's services, but never decides the domain's behavior.**
Every arrow above follows that; the direction Application -> Domain ->
Storage is the classic "services against repository interfaces" shape, with
the repositories (Storage) and the queue jobs (Queue) carrying the domain's
types rather than the other way around.