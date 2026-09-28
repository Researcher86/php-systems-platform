# Final Platform

## Project

`php-systems-platform`

## Goal

Build a single educational systems platform that integrates the components developed throughout the PHP Systems Lab into one coherent backend system.

The final platform should demonstrate how:

* HTTP requests enter the system
* application logic is executed
* concurrent work is scheduled
* worker processes execute jobs
* data is stored
* cached data is served
* background jobs are queued
* processes communicate
* backpressure is applied
* failures are detected and recovered
* metrics are collected
* the whole system behaves under load

This is an **educational systems laboratory**, not a production-ready framework.

The main goal is understanding the interaction between the components and the underlying operating-system/runtime concepts.

---

# 1. Integrated Components

The platform should integrate the following projects.

### Core runtime

* `php-concurrency`
* `php-memory-lab`
* `php-worker-pool`

### Networking

* `php-mini-http-server`

### Storage

* `php-mini-database`

### Cache

* `php-mini-cache`

### Asynchronous processing

* `php-job-queue`

### Benchmarking / experiments

* `php-benchmark-lab`

The final project itself is:

```text
php-systems-platform/
```

The individual repositories remain understandable and usable independently.

The platform acts as the integration layer rather than replacing them.

---

# 2. Target Architecture

The initial target architecture:

```text
                         ┌──────────────────────┐
                         │      HTTP Client     │
                         └──────────┬───────────┘
                                    │
                                    ▼
                         ┌──────────────────────┐
                         │ php-mini-http-server │
                         └──────────┬───────────┘
                                    │
                                    ▼
                         ┌──────────────────────┐
                         │   Application Layer  │
                         │                      │
                         │  Routing             │
                         │  Controllers         │
                         │  Services            │
                         └──────┬─────┬─────┬───┘
                                │     │     │
                 ┌──────────────┘     │     └──────────────┐
                 ▼                    ▼                    ▼
        ┌────────────────┐   ┌────────────────┐   ┌────────────────┐
        │  Mini Cache    │   │ Mini Database  │   │  Job Queue     │
        │                │   │                │   │                │
        │ php-mini-cache │   │php-mini-       │   │ php-job-queue  │
        │                │   │database        │   │                │
        └────────────────┘   └────────────────┘   └───────┬────────┘
                                                            │
                                                            ▼
                                                   ┌────────────────┐
                                                   │  Worker Pool   │
                                                   │                │
                                                   │ php-worker-pool│
                                                   └───────┬────────┘
                                                           │
                         ┌─────────────────────────────────┼───────────────┐
                         │                                 │               │
                         ▼                                 ▼               ▼
                ┌────────────────┐                ┌────────────────┐ ┌─────────────┐
                │ Database       │                │ Cache          │ │ External /  │
                │ operations     │                │ operations     │ │ simulated   │
                └────────────────┘                └────────────────┘ │ work        │
                                                                     └─────────────┘

                         ┌──────────────────────────────────────────────┐
                         │ Observability / Metrics / Experiments       │
                         │                                              │
                         │ php-benchmark-lab                            │
                         │ latency / throughput / queue depth / memory  │
                         └──────────────────────────────────────────────┘
```

---

# 3. Main Demonstration Scenario

The platform should have one realistic end-to-end scenario.

Use a simple domain such as:

```text
Product / Order / Task
```

The exact business domain is not important.

The systems behavior is important.

Example:

```text
POST /orders
```

The application:

1. validates the request
2. writes an order to the database
3. invalidates/updates the cache
4. publishes background jobs
5. returns an HTTP response
6. workers process the background jobs
7. workers update the database/cache
8. metrics record the entire operation

Then:

```text
GET /orders/{id}
```

should demonstrate:

```text
HTTP
  ↓
Application
  ↓
Cache
  ├── HIT  → response
  │
  └── MISS
       ↓
     Database
       ↓
     Cache
       ↓
    response
```

This gives the platform a concrete workload instead of being just a collection of technical demos.

---

# 4. Design Principles

## 4.1 Components remain independent

Do not copy implementation code from the individual repositories into the platform.

Prefer:

```text
php-systems-platform
        │
        ├── dependency → php-mini-cache
        ├── dependency → php-mini-database
        ├── dependency → php-worker-pool
        └── dependency → php-job-queue
```

The platform should demonstrate integration through explicit interfaces.

---

## 4.2 Avoid a giant abstraction layer

Do not build:

```text
UniversalStorageInterface
UniversalCacheInterface
UniversalWorkerInterface
UniversalEverythingInterface
```

just for architectural purity.

Use small interfaces where they clarify integration.

---

## 4.3 Keep the execution model visible

The code should make it possible to understand:

* which process is executing
* which component owns the work
* where blocking occurs
* where concurrency occurs
* where data crosses process boundaries
* where data is copied
* where queues grow
* where backpressure occurs

Avoid hiding everything behind a framework.

---

## 4.4 Failure is part of the platform

The final system should deliberately demonstrate failures.

Examples:

```text
worker crashes
queue becomes full
database operation becomes slow
cache becomes unavailable
request times out
job processing fails
consumer stops
process receives SIGTERM
```

The goal is not to make the system magically resilient.

The goal is to make failure behavior observable and understandable.

---

# 5. Repository Structure

Initial structure:

```text
php-systems-platform/
├── bin/
│   └── platform
│
├── config/
│   ├── platform.php
│   └── routes.php
│
├── src/
│   ├── Application/
│   │   ├── Application.php
│   │   ├── Container.php
│   │   └── ApplicationContext.php
│   │
│   ├── Http/
│   │   ├── Router.php
│   │   ├── Request.php
│   │   └── Response.php
│   │
│   ├── Domain/
│   │   ├── Order.php
│   │   └── OrderService.php
│   │
│   ├── Storage/
│   │   ├── Database.php
│   │   └── Repositories/
│   │
│   ├── Cache/
│   │   └── CacheService.php
│   │
│   ├── Queue/
│   │   ├── Job.php
│   │   ├── JobDispatcher.php
│   │   └── JobConsumer.php
│   │
│   ├── Workers/
│   │   ├── WorkerManager.php
│   │   └── WorkerTask.php
│   │
│   └── Observability/
│       ├── Metrics.php
│       ├── Trace.php
│       └── Logger.php
│
├── tests/
│   ├── Unit/
│   ├── Integration/
│   ├── System/
│   └── Failure/
│
├── benchmarks/
│
├── examples/
│
├── docs/
│
├── composer.json
├── README.md
└── docker-compose.yml
```

The exact structure can evolve during implementation.

The important part is separation between:

```text
HTTP
Application
Domain
Infrastructure
Workers
Observability
```

---

# 6. Phase 5 Implementation Steps

## Step 1 — Create the platform skeleton

Create:

```text
php-systems-platform
```

Add:

* Composer project
* PSR-4 autoloading
* PHP version requirement
* PHPUnit
* PHPStan/Psalm if already used by the other projects
* basic CLI entry point
* basic README
* basic configuration

Initial command:

```bash
php bin/platform.php
```

Expected:

```text
PHP Systems Platform

Commands:

  serve
  worker
  queue
  status
  benchmark
  demo
```

Do not implement real functionality yet.

### Completion criteria

```text
composer install
php bin/platform.php
```

works from a clean checkout.

---

# 7. Step 2 — Integrate php-mini-http-server

The HTTP server becomes the external entry point.

Implement:

```text
GET  /health
GET  /status
GET  /orders/{id}
POST /orders
POST /jobs
```

Start with:

```text
GET /health
```

Expected:

```json
{
    "status": "ok"
}
```

Then introduce the application dispatcher:

```text
HTTP server
    ↓
Request
    ↓
Router
    ↓
Controller
    ↓
Response
```

Do not connect the database or queue yet.

### Completion criteria

The platform can serve HTTP requests using `php-mini-http-server`.

---

# 8. Step 3 — Application Layer

Introduce the main application abstraction.

Example:

```php
final class Application
{
    public function handle(Request $request): Response
    {
        // route and execute request
    }
}
```

The server should not know about:

* database implementation
* cache implementation
* queue internals
* worker implementation

It only knows:

```text
Request → Application → Response
```

This is an important architectural boundary.

---

# 9. Step 4 — Integrate php-mini-database

Add the database dependency.

Implement:

```text
Database
Repository
Domain service
```

Example:

```text
OrderService
    ↓
OrderRepository
    ↓
php-mini-database
```

Create the minimum schema:

```text
orders
```

Possible fields:

```text
id
status
created_at
updated_at
payload
```

Implement:

```text
createOrder()
getOrder()
updateOrder()
deleteOrder()
```

Do not build a large ORM.

The point is to exercise the database component.

---

# 10. Step 5 — First End-to-End Request

Implement:

```text
POST /orders
```

Flow:

```text
HTTP
 ↓
Router
 ↓
OrderController
 ↓
OrderService
 ↓
OrderRepository
 ↓
php-mini-database
 ↓
HTTP response
```

Expected:

```json
{
    "id": 123,
    "status": "created"
}
```

At this point the platform has its first complete synchronous path.

---

# 11. Step 6 — Integrate php-mini-cache

Introduce:

```text
CacheService
```

Implement:

```text
GET /orders/{id}
```

Flow:

```text
Request
   ↓
Cache lookup
   │
   ├── HIT ───────→ Response
   │
   └── MISS
          ↓
      Database
          ↓
        Cache
          ↓
       Response
```

Record:

```text
cache.hit
cache.miss
cache.set
cache.delete
```

Do not make cache usage mandatory everywhere.

Only use it where it demonstrates a useful pattern.

---

# 12. Step 7 — Cache Invalidation

For:

```text
POST /orders
```

and:

```text
PUT /orders/{id}
```

define the cache consistency behavior.

Example:

```text
Database update
      ↓
Cache invalidation
```

Do not attempt to build a sophisticated distributed consistency protocol.

The educational goal is to make the consistency trade-off visible.

Document:

```text
Database is the source of truth.
Cache is derived state.
```

---

# 13. Step 8 — Integrate php-job-queue

Introduce background jobs.

Define a common job model:

```php
interface Job
{
    public function execute(JobContext $context): void;
}
```

Example jobs:

```text
OrderCreatedJob
UpdateOrderCacheJob
GenerateOrderReportJob
SendNotificationJob
```

Start with:

```text
OrderCreatedJob
```

The request should be able to:

```text
write database
    ↓
enqueue job
    ↓
return HTTP response
```

instead of doing all work synchronously.

---

# 14. Step 9 — Queue Lifecycle

Implement:

```text
producer
   ↓
queue
   ↓
consumer
   ↓
job execution
```

Add CLI commands:

```bash
php bin/platform.php queue:publish
php bin/platform.php queue:consume
php bin/platform.php queue:status
```

Expose queue metrics:

```text
queue.depth
queue.published
queue.completed
queue.failed
queue.retried
```

---

# 15. Step 10 — Integrate php-worker-pool

Now introduce actual worker processes.

Architecture:

```text
Queue Consumer
      ↓
Worker Manager
      ↓
┌────────┬────────┬────────┬────────┐
│ Worker │ Worker │ Worker │ Worker │
│   #1   │   #2   │   #3   │   #4   │
└────────┴────────┴────────┴────────┘
```

Workers execute jobs received from the queue.

The worker pool should remain responsible for:

* worker lifecycle
* process management
* task dispatch
* worker completion
* worker failure
* shutdown

It should not know about:

* orders
* HTTP
* business logic

---

# 16. Step 11 — Worker Lifecycle

Make worker states observable.

For example:

```text
STARTING
    ↓
IDLE
    ↓
BUSY
    ↓
IDLE
```

Shutdown:

```text
IDLE/BUSY
    ↓
DRAINING
    ↓
STOPPING
    ↓
DEAD
```

Expose:

```text
worker.id
worker.state
worker.pid
worker.tasks_completed
worker.tasks_failed
worker.started_at
```

This connects the final platform directly to the worker-pool experiments.

---

# 17. Step 12 — Queue + Worker Pool

Connect:

```text
Job Queue
    ↓
Consumer
    ↓
Worker Pool
    ↓
Job
```

Test:

```text
100 jobs
4 workers
```

Then:

```text
1000 jobs
4 workers
```

Then:

```text
1000 jobs
8 workers
```

Measure:

```text
total processing time
throughput
average latency
p95 latency
queue depth
worker utilization
```

The purpose is to show that increasing workers does not automatically produce linear speedup.

---

# 18. Step 13 — Concurrent Database Work

Use the existing concurrency primitives to demonstrate parallel I/O.

Example job:

```text
ProcessOrderJob
```

performs:

```text
load order
load customer
load product
load inventory
```

Where possible, execute independent operations concurrently.

Conceptually:

```text
             ┌── database query A
Request ─────┼── database query B
             ├── cache query
             └── external/simulated operation
```

Compare:

```text
sequential
```

against:

```text
concurrent
```

Do not add concurrency merely because it is possible.

Only parallelize genuinely independent work.

---

# 19. Step 14 — Integrate php-concurrency

Use `php-concurrency` as the educational foundation for:

* process creation
* IPC
* task scheduling
* concurrent execution
* synchronization

The final platform should contain at least one explicit demonstration where the concurrency primitive is visible.

Example:

```text
ConcurrentTaskRunner
```

with:

```php
$runner->run([
    $taskA,
    $taskB,
    $taskC,
]);
```

Internally this should use the mechanisms studied in `php-concurrency`.

---

# 20. Step 15 — Integrate php-memory-lab Concepts

The platform should expose memory behavior rather than turning `php-memory-lab` into a runtime dependency.

Add a diagnostic command:

```bash
php bin/platform.php memory:demo
```

Demonstrate:

```text
parent process
     ↓ fork
child process
```

and show:

```text
RSS before fork
RSS after fork
RSS after modification
```

Demonstrate copy-on-write.

This connects the worker architecture to the memory model.

---

# 21. Step 16 — Worker Memory Experiment

Create:

```bash
php bin/platform.php workers:memory
```

Run:

```text
1 worker
2 workers
4 workers
8 workers
```

Measure:

```text
parent RSS
worker RSS
total RSS
startup memory
steady-state memory
```

Then compare:

```text
shared initial memory
```

with:

```text
memory after mutation
```

The purpose is to show why process-based concurrency has different memory characteristics from threads/coroutines.

---

# 22. Step 17 — Backpressure

Backpressure should emerge naturally from the queue + worker architecture.

Example:

```text
HTTP producers
      ↓
    Queue
      ↓
Worker Pool
```

Generate work faster than workers can process it.

Observe:

```text
producer rate > consumer rate
```

which causes:

```text
queue depth ↑
```

Implement a configurable limit:

```text
MAX_QUEUE_SIZE
```

When the queue reaches the limit, the system must have an explicit behavior.

For example:

```text
reject
block
delay
or return 429
```

Choose one simple policy and document it.

The important part is that overload is handled deliberately rather than accidentally.

---

# 23. Step 18 — Timeouts

Introduce explicit timeouts at the important boundaries.

At minimum:

```text
HTTP request timeout
job execution timeout
worker operation timeout
database operation timeout
queue operation timeout
```

Distinguish:

```text
request timeout
```

from:

```text
execution timeout
```

and:

```text
worker lifecycle timeout
```

This should follow the timeout model already explored in `php-worker-pool`.

---

# 24. Step 19 — Retry Policy

Add controlled retries for jobs.

Example:

```text
attempt 1
   ↓ failure
attempt 2
   ↓ failure
attempt 3
   ↓ failure
dead / failed
```

Job metadata:

```text
job_id
attempt
max_attempts
created_at
started_at
completed_at
last_error
```

Do not retry every possible error.

Document which failures are retryable.

---

# 25. Step 20 — Idempotency

The platform must demonstrate why background jobs need idempotency.

Example:

```text
Job A
 ↓
database update
 ↓
worker crashes before acknowledgement
 ↓
Job A is delivered again
```

The system must tolerate:

```text
same job executed more than once
```

Use an idempotency key:

```text
job_id
```

or another explicit operation identifier.

Demonstrate:

```text
at-least-once delivery
```

and explain why:

```text
at-least-once != exactly-once
```

---

# 26. Step 21 — Graceful Shutdown

The platform must support:

```text
SIGTERM
SIGINT
```

Shutdown sequence:

```text
receive signal
      ↓
stop accepting new work
      ↓
stop pulling new jobs
      ↓
finish currently executing jobs
      ↓
drain workers
      ↓
stop workers
      ↓
close resources
      ↓
exit
```

Verify that jobs are not silently lost.

This should integrate directly with the worker-pool lifecycle.

---

# 27. Step 22 — Failure Injection

Create a controlled failure mode.

Example:

```text
POST /debug/fail-worker
```

or a job:

```text
FailingJob
```

that intentionally crashes/fails.

Test:

```text
worker crashes
     ↓
worker manager detects failure
     ↓
worker removed
     ↓
replacement worker started
```

Then test:

```text
job fails
     ↓
retry
     ↓
failure
     ↓
dead/failed state
```

Failure injection should only be enabled in development/demo mode.

---

# 28. Step 23 — Observability

Introduce a common event/metrics model.

Minimum metrics:

## HTTP

```text
http.requests
http.errors
http.request_duration
```

## Cache

```text
cache.hit
cache.miss
cache.operations
```

## Database

```text
db.operations
db.errors
db.operation_duration
```

## Queue

```text
queue.depth
queue.published
queue.completed
queue.failed
queue.retried
```

## Workers

```text
workers.active
workers.busy
workers.idle
workers.failed
worker.task_duration
```

## Memory

```text
process.rss
worker.rss
```

---

# 29. Step 24 — Request Trace

Create a simple request identifier:

```text
request_id
```

Propagate it through:

```text
HTTP request
    ↓
Application
    ↓
Database
    ↓
Queue
    ↓
Worker
    ↓
Job
```

For asynchronous jobs also keep:

```text
request_id
job_id
```

This allows the final demo to answer:

```text
Which HTTP request created this job?
Which worker executed it?
How long did it take?
Did it retry?
```

Do not implement a complete OpenTelemetry clone.

A small educational tracing system is enough.

---

# 30. Step 25 — Platform Status

Implement:

```bash
php bin/platform.php status
```

Example output:

```text
PHP Systems Platform
--------------------

HTTP Server
  status:       running
  requests:     12,430
  errors:       14

Cache
  status:       running
  hits:         8,102
  misses:       1,430

Database
  status:       running
  operations:   9,532

Queue
  status:       running
  depth:        23
  processed:    4,821
  failed:       12

Workers
  total:        4
  idle:         2
  busy:         2
  failed:       0

Memory
  master RSS:   24 MB
  workers RSS:  82 MB
```

This becomes the simplest way to see the whole platform.

---

# 31. Step 26 — Integrated Demo

Create:

```bash
php bin/platform.php demo
```

The demo should automatically:

1. start the platform
2. initialize database
3. initialize cache
4. start workers
5. start queue consumer
6. generate sample requests
7. create orders
8. generate background jobs
9. process jobs
10. print metrics
11. intentionally generate one failure
12. demonstrate retry
13. demonstrate worker recovery
14. perform graceful shutdown

Expected output should tell a story:

```text
Starting platform...

HTTP server ........ OK
Database ........... OK
Cache .............. OK
Queue .............. OK
Workers ............ 4

Creating orders...
Created 100 orders

Publishing jobs...
Published 100 jobs

Processing...
Worker #1 processed 28 jobs
Worker #2 processed 25 jobs
Worker #3 processed 24 jobs
Worker #4 processed 23 jobs

Injecting failure...
Worker #2 exited unexpectedly

Recovering...
Worker #2 restarted

Retrying failed jobs...
Completed

Final statistics...
```

This is the main showcase of the entire PHP Systems Lab.

---

# 32. Step 27 — Integration Tests

Create integration tests for every boundary.

### HTTP → Application

```text
request routing
response handling
errors
```

### Application → Database

```text
create
read
update
transaction/error behavior
```

### Application → Cache

```text
hit
miss
invalidation
```

### Application → Queue

```text
publish
consume
failure
retry
```

### Queue → Worker Pool

```text
dispatch
completion
worker failure
replacement
```

---

# 33. Step 28 — End-to-End Tests

At least these scenarios should exist.

### Scenario 1 — Create order

```text
POST /orders
```

Verify:

```text
database contains order
job exists
HTTP response is correct
```

### Scenario 2 — Read cached order

```text
GET /orders/1
GET /orders/1
```

Verify:

```text
first request → cache miss
second request → cache hit
```

### Scenario 3 — Background processing

```text
POST /orders
```

Verify:

```text
job published
worker receives job
job completed
```

### Scenario 4 — Worker crash

Verify:

```text
worker failure
worker replacement
job recovery
```

### Scenario 5 — Retry

Verify:

```text
failure
retry
success
```

### Scenario 6 — Queue overload

Generate more jobs than workers can process.

Verify:

```text
queue grows
backpressure activates
system remains bounded
```

### Scenario 7 — Graceful shutdown

Send:

```text
SIGTERM
```

Verify:

```text
new work stops
active jobs finish
workers exit
```

---

# 34. Step 29 — Load Tests

The final platform needs measurable experiments.

At minimum:

## Test A — HTTP only

```text
GET /health
```

Measure:

```text
RPS
latency
CPU
memory
```

## Test B — Database

```text
GET /orders/{id}
```

without cache.

Measure:

```text
RPS
latency
database operations
```

## Test C — Cache

Same endpoint with cache enabled.

Compare:

```text
cache hit
cache miss
```

## Test D — Background jobs

Generate:

```text
1,000 jobs
```

Measure:

```text
throughput
queue depth
processing latency
```

## Test E — Worker scaling

Compare:

```text
1 worker
2 workers
4 workers
8 workers
```

Do not assume linear scaling.

Record actual results.

---

# 35. Step 30 — Failure/Overload Experiments

The platform should contain reproducible experiments.

> Implemented by `php bin/platform.php experiments` - five scenarios, each
> against a platform the command owns and stops, all five observable through
> the platform's own `/metrics`, `/queue/status`, `/workers` and `X-Cache`
> rather than a second instrumentation. The one new production seam is the
> `database.delay_ms` config (env `DATABASE_LATENCY_MS`), honored by
> `Database::read/write` only where failure injection is armed. Shared
> process lifecycle lives in `src/Support/OwnedProcess` (used by both the
> load run and the experiments).

### Experiment 1

```text
Slow workers
```

Observe:

```text
queue depth ↑
```

Implemented: a fixed two-worker pool, eight `demo.slow` (1.0s) jobs, journal
depth sampled over time - `8 -> 6 -> 4 -> 2 -> 0`, peak 8, drained.

### Experiment 2

```text
Worker crash
```

Observe:

```text
worker count ↓
recovery
```

Implemented: `POST /debug/fail-worker` against a two-worker pool, worker pids
sampled before and after - the crashed pid disappears and a replacement pid
appears, the manager's detection-and-replacement evidenced by the pid change.

### Experiment 3

```text
Cache unavailable
```

Observe:

```text
cache failure
database fallback
```

Implemented: the experiment owns the cache server (serve adopts an
already-running one), reads are `X-Cache: hit`, the cache is killed, the same
read answers `200 X-Cache: miss` from the database and `db.operations` rises.

### Experiment 4

```text
Database slow
```

Observe:

```text
request latency
worker utilization
queue growth
```

Implemented: `DATABASE_LATENCY_MS=300` on a serve without a cache tier, so
every read reaches the database - POST pays the delay, GET pays the delay,
`db.operation_duration` reports ~300ms. The delay is the one genuinely new
production code in the step, gated on the same environment notion as failure
injection.

### Experiment 5

```text
Queue full
```

Observe:

```text
backpressure
producer behavior
```

Implemented: `QUEUE_MAX_SIZE=5` with a single worker and a backlog of slow
jobs; the producer answers `429 Retry-After: 1` with `queueDepth` while at
capacity, and the same call is accepted once the queue drains below it -
backpressure is a signal, not a wall.

These experiments are more valuable than simply reporting benchmark numbers.

---

# 36. Step 31 — Benchmark Report

Create:

```text
docs/benchmarks.md
```

Every benchmark should contain:

```text
Environment
Configuration
Workload
Number of workers
Concurrency
Dataset size
Result
Interpretation
```

Example:

```text
Workers: 1
Jobs: 10,000
Average job time: 10 ms

Workers: 4
Jobs: 10,000
Average job time: 10 ms

Observed throughput:
...
```

Avoid presenting benchmark results without describing the workload.

> Implemented: `docs/benchmarks.md` documents all four benchmarks (queue,
> load tests, order loading, worker memory), each with the eight required
> fields and real numbers from one run of the platform's own commands
> (`benchmark`, `load`, `orders:compare`, `workers:memory`). The queue
> benchmark caps at the command's own 5,000-job limit and is documented at
> that size rather than at the example's 10,000.

---

# 37. Step 32 — Architecture Documentation

Create:

```text
docs/
├── architecture.md
├── concurrency.md
├── memory.md
├── workers.md
├── queue.md
├── cache.md
├── database.md
├── backpressure.md
├── failure-handling.md
├── observability.md
└── benchmarks.md
```

The documentation should explain not only **what** the platform does, but **why**.

> Implemented: all ten documents exist in `docs/`. Each explains the *why*
> behind a decision, not just the what: the producer/consumer process split
> and ownership rules (`architecture.md`), when concurrency pays and the two
> fan-out bargains (`concurrency.md`), the copy-on-write memory price of
> process concurrency (`memory.md`), the pool lifecycle and its four distinct
> timeouts (`workers.md`), the journal as the queue's source of truth
> (`queue.md`), cache-as-derived-state and "down vs disabled"
> (`cache.md`), the three database timeouts and the slow-database seam
> (`database.md`), reject-not-block backpressure (`backpressure.md`), the
> five failure modes and controlled reproduction (`failure-handling.md`),
> and the one-request-one-chain trace model (`observability.md`).
> `benchmarks.md` was created by Step 31.

---

# 38. Step 33 — Architecture Diagram

README should contain one high-level diagram:

```text
                    HTTP
                     │
                     ▼
              ┌─────────────┐
              │ HTTP Server │
              └──────┬──────┘
                     │
                     ▼
              ┌─────────────┐
              │ Application │
              └──┬────┬───┬─┘
                 │    │   │
          ┌──────┘    │   └────────┐
          ▼           ▼            ▼
        Cache      Database      Queue
                                   │
                                   ▼
                              Worker Pool
                              ┌──┬──┬──┐
                              ▼  ▼  ▼  ▼
                            Jobs / Tasks

          ┌─────────────────────────────────┐
          │ Metrics / Tracing / Memory      │
          └─────────────────────────────────┘
```

> Implemented: the README `## Architecture` section carries this one
> high-level diagram (HTTP -> HTTP Server -> Application -> Cache/Database/
> Queue -> Worker Pool -> Workers -> Jobs, with the cross-cutting box), now
> annotated with the process ownership that makes the shape work - `serve`,
> `queue:consume` and `bin/worker.php` - tying the diagram to
> `docs/architecture.md`.

---

# 39. Step 34 — Dependency Boundaries

Review all dependencies.

The desired dependency direction:

```text
Application
    ↓
Interfaces
    ↓
Infrastructure implementations
```

Avoid:

```text
Database → HTTP
Cache → Domain
Worker → Controller
Queue → HTTP server
```

Infrastructure components should not become coupled to business logic.

> Implemented: the dependency review is documented in
> `docs/architecture.md` (Dependency boundaries), built from the real
> cross-namespace imports. Three of the four avoided directions are absent
> in code (`Database -> HTTP`, `Worker -> Controller`, `Queue -> HTTP
> server`). `Cache -> Domain` exists but only to the `Order` *type* for
> serialization and keying - never to business behavior - and that one nuance
> is called out explicitly rather than hidden.

---

# 40. Step 35 — Resource Ownership

Explicitly document ownership.

For example:

```text
HTTP server
    owns sockets

Application
    owns request lifecycle

Database
    owns database state

Cache
    owns cached state

Queue
    owns pending jobs

Worker pool
    owns worker processes

Job
    owns business operation
```

This is important because process ownership and resource ownership are central topics of the entire lab.

> Implemented: `docs/ownership.md` - the full ownership map (sockets,
> request lifecycle, database/cache state, worker processes, pending jobs,
> one job's execution), the three rules that make ownership unambiguous (a
> process stops only what it started; a pid file is authoritative but the
> port is ground truth; the pool is owned once and adopted freely), and the
> graceful-stop contract. Linked from the README docs table.

---

# 41. Step 36 — Process Model

Document the final process tree.

Example:

```text
platform master
│
├── HTTP server
│
├── queue consumer
│
├── worker #1
├── worker #2
├── worker #3
└── worker #4
```

If the architecture uses a different model, document the actual model rather than forcing this exact structure.

The process tree should be inspectable using standard OS tools.

> Implemented: `docs/process-model.md` documents the actual model - `serve`
> owns the pool Master and the cache, `queue:consume` adopts the pool and
> runs its four forwarders, `minidb.php` daemonizes (ppid 1) - captured from
> a live `ps` read, with the exact `ps`/`pstree` commands, what each line
> proves, and why the correct startup order (serve, then consumer) makes the
> tree deterministic.

---

# 42. Step 37 — Shutdown and Recovery Matrix

Create:

```text
docs/failure-matrix.md
```

Example:

| Failure                 | Expected behavior             |
| ----------------------- | ----------------------------- |
| HTTP client disconnects | Request terminates safely     |
| Worker crashes          | Worker is detected/replaced   |
| Job fails               | Retry according to policy     |
| Job exceeds timeout     | Job marked failed/retryable   |
| Queue is full           | Backpressure policy activates |
| Cache unavailable       | Defined fallback behavior     |
| Database error          | Request/job fails explicitly  |
| SIGTERM                 | Graceful shutdown             |
| SIGINT                  | Graceful shutdown             |

> Implemented: `docs/failure-matrix.md` - the full matrix (the nine required
> rows plus the platform's own: retryable-vs-foregone job failure, execution
> timeout, slow database, idempotent redelivery, ignored SIGTERM, cold-start
> restore), each with how it is observed and how it is reproduced, and the
> two independent recovery axes (workers vs jobs).

---

# 43. Step 38 — Final CLI

The final CLI should expose the important concepts.

```bash
php bin/platform.php serve

php bin/platform.php worker

php bin/platform.php queue:consume

php bin/platform.php queue:status

php bin/platform.php status

php bin/platform.php demo

php bin/platform.php benchmark

php bin/platform.php memory:demo

php bin/platform.php workers:memory

php bin/platform.php failure:demo
```

The CLI itself should remain small.

Do not turn it into another framework.

> Implemented (verification): all ten required commands exist and run
> (`serve`, `worker`, `queue:consume`, `queue:status`, `status`, `demo`,
> `benchmark`, `memory:demo`, `workers:memory`, `failure:demo`), plus the
> experiment/demo extras added by later steps (`load`, `experiments`,
> `orders:compare`, `idempotency:demo`, `metrics`, `trace`, `queue:job`,
> `queue:publish`, `workers:status`). The dispatch stays small and
> framework-free: a `COMMANDS` const + a `match`, one file, no command
> objects, no container, no autodiscovery - heavy command bodies live in
> their own classes (`PlatformDemo`, `LoadTestRunner`,
> `FailureExperiments`) where they were built.

---

# 44. Step 39 — Docker Environment

Create a reproducible environment.

Minimum:

```text
php-systems-platform
```

with all required local services.

Keep the Docker setup simple.

The purpose is:

```text
git clone
docker compose up
```

and then:

```bash
php bin/platform.php demo
```

should work.

> Implemented: the Docker setup was already the working environment for every
> command in this lab - one `php` service (php:8.5-cli with pcntl, posix,
> sockets, shmop, sysv*, ffi, composer) mounting the repo to `/app`. Verified
> the acceptance criterion end to end: `docker compose up` starts the
> container and `php bin/platform.php demo` runs the whole platform inside
> it (103 HTTP requests, worker crash detected in 1.0 ms, replacement in
> 1.7 ms, graceful shutdown, exit clean). Added `.dockerignore` so the build
> context stays clean on a fresh clone.

---

# 45. Step 40 — Clean Installation Test

Before declaring Phase 5 complete:

```bash
rm -rf vendor
composer install
```

Then:

```bash
docker compose up -d
php bin/platform.php demo
```

Run:

```bash
composer test
```

and the complete integration suite.

The platform must work from a clean environment.

> Implemented (verification): `rm -rf vendor && composer install` (73
> packages, clean), then `php bin/platform.php demo` (103 requests, 100 jobs
> processed, crash detected 1.0 ms / replaced 1.7 ms, graceful shutdown),
> then `composer test` - OK (250 tests, 1161 assertions) - plus PHPStan
> clean and format 0 of 132 files. The platform works from a clean
> environment.

---

# 46. Final Test Matrix

The project should have at least four test levels.

## Unit

Test:

```text
Router
Domain services
Job model
Retry policy
Backpressure policy
Metrics
```

## Integration

Test:

```text
Database
Cache
Queue
Worker pool
HTTP server
```

## System

Test:

```text
HTTP → Application → DB
HTTP → Application → Cache
HTTP → Queue → Worker
```

## End-to-end

Test:

```text
HTTP
 ↓
Application
 ↓
DB + Cache + Queue
 ↓
Workers
 ↓
Job
 ↓
Metrics
```

---

# 47. Final Demo Scenario

The final README should contain one complete walkthrough.

Example:

```text
1. Start platform

2. Create order

POST /orders

3. Observe database write

4. Observe job publication

5. Observe worker processing

6. Read order

GET /orders/1

7. Read again

GET /orders/1

8. Observe cache hit

9. Inject worker failure

10. Observe worker recovery

11. Generate load

12. Observe queue growth

13. Increase workers

14. Observe throughput change

15. Send SIGTERM

16. Observe graceful shutdown
```

This becomes the final "story" of the project.

> Implemented (verified): the README's `# Run the Demo` section is the one
> complete walkthrough - start platform, initialize components, create
> orders, publish jobs, process with workers, inject a controlled failure,
> recover the worker, retry failed work, final statistics, graceful
> shutdown - with real example output. The walkthrough's other beats (cache
> hit on re-read, load, queue growth, throughput, SIGTERM) are each a
> dedicated documented command (`load`, `experiments`, `orders:compare`,
> `benchmark`) rather than stages of the demo script, and are linked from
> the same README.

---

# 48. Final Repository Structure

Target:

```text
php-systems-platform/
│
├── bin/
│   └── platform
│
├── config/
│
├── src/
│   ├── Application/
│   ├── Http/
│   ├── Domain/
│   ├── Storage/
│   ├── Cache/
│   ├── Queue/
│   ├── Workers/
│   └── Observability/
│
├── tests/
│   ├── Unit/
│   ├── Integration/
│   ├── System/
│   └── Failure/
│
├── benchmarks/
│
├── examples/
│
├── docs/
│   ├── architecture.md
│   ├── concurrency.md
│   ├── memory.md
│   ├── workers.md
│   ├── queue.md
│   ├── cache.md
│   ├── database.md
│   ├── backpressure.md
│   ├── failure-handling.md
│   ├── observability.md
│   └── benchmarks.md
│
├── composer.json
├── docker-compose.yml
├── README.md
└── LICENSE
```

> Implemented: the actual structure is the target with three honest
> deviations. `bin/` is `platform.php`, `minidb.php`, `cache.php`,
> `worker.php` (one launcher per long-running process, not a single
> `platform`). `src/` is a superset: `Application`, `Http`, `Domain`,
> `Storage`, `Cache`, `Queue`, `Workers`, `Observability` plus `Cli` (the
> command entry), `Benchmarks`/`Experiments`/`Demo`/`Memory`/`Support` (the
> command bodies and shared seams). `tests/` is `Unit`/`Integration`/`E2E`/
> `Support`: the target's "System" level is `tests/Integration/Boundary`
> (HTTP -> Application -> DB/Cache/Queue, Queue -> WorkerPool) and the
> "Failure" level is the failure suites (`FailureDemoCommandTest`,
> `FailureExperimentsCommandTest`, `WorkerFailureTasksTest`). `benchmarks/`
> and `examples/` have no separate directory: benchmark commands live in
> `src/Benchmarks` and are measured in `docs/benchmarks.md`, and the demo
> command is the example. `docs/` is a superset (added `ownership.md`,
> `process-model.md`, `failure-matrix.md`).

---

# 49. Implementation Order

Do not implement everything simultaneously.

Use this exact dependency order:

```text
01. Project skeleton
        ↓
02. HTTP server
        ↓
03. Application layer
        ↓
04. Database
        ↓
05. First synchronous request
        ↓
06. Cache
        ↓
07. Cache invalidation
        ↓
08. Job queue
        ↓
09. Queue consumer
        ↓
10. Worker pool
        ↓
11. Queue + worker integration
        ↓
12. Concurrency
        ↓
13. Memory experiments
        ↓
14. Backpressure
        ↓
15. Timeouts
        ↓
16. Retries
        ↓
17. Idempotency
        ↓
18. Graceful shutdown
        ↓
19. Failure injection
        ↓
20. Observability
        ↓
21. Request tracing
        ↓
22. Status command
        ↓
23. Integrated demo
        ↓
24. Integration tests
        ↓
25. E2E tests
        ↓
26. Load tests
        ↓
27. Failure experiments
        ↓
28. Benchmark report
        ↓
29. Documentation
        ↓
30. Clean-install verification
        ↓
31. Phase 5 freeze
```

---

# 50. Definition of Done

Phase 5 is complete when all of the following are true.

## Architecture

* [x] All selected Systems Lab components are integrated.
* [x] Components retain clear boundaries.
* [x] No unnecessary framework abstraction has been introduced.
* [x] Process ownership is documented.
* [x] Resource ownership is documented.

## HTTP

* [x] HTTP server works.
* [x] Routing works.
* [x] Health endpoint works.
* [x] Order API works.

## Database

* [x] Orders can be created.
* [x] Orders can be read.
* [x] Updates work.
* [x] Database errors are handled.

## Cache

* [x] Cache hit works.
* [x] Cache miss works.
* [x] Database fallback works.
* [x] Invalidation works.

## Queue

* [x] Jobs can be published.
* [x] Jobs can be consumed.
* [x] Queue depth is observable.
* [x] Failed jobs are handled.
* [x] Retry policy works.
* [x] Idempotency is demonstrated.

## Worker pool

* [x] Multiple workers can execute jobs.
* [x] Worker lifecycle is observable.
* [x] Worker failure is detected.
* [x] Worker replacement works.
* [x] Graceful shutdown works.

## Concurrency

* [x] At least one real concurrent workload is demonstrated.
* [x] Sequential vs concurrent behavior can be compared.
* [x] Concurrency limits are explicit.

## Memory

* [x] Process memory can be measured.
* [x] Fork/COW behavior is demonstrated.
* [x] Worker memory usage can be compared.

## Backpressure

* [x] Queue overload can be reproduced.
* [x] Queue growth is observable.
* [x] Backpressure policy is explicit.
* [x] The system remains bounded under overload.

## Observability

* [x] Metrics exist.
* [x] Request IDs exist.
* [x] Job IDs exist.
* [x] Basic request/job tracing works.
* [x] Platform status is available.

## Failure handling

* [x] Worker crash can be reproduced.
* [x] Job failure can be reproduced.
* [x] Retry can be reproduced.
* [x] Timeout can be reproduced.
* [x] Graceful shutdown can be reproduced.

## Testing

* [x] Unit tests pass.
* [x] Integration tests pass.
* [x] System tests pass.
* [x] E2E tests pass.
* [x] Load tests run, and their actual results are recorded.
* [x] Failure tests pass.

## Documentation

* [x] Architecture is documented.
* [x] Process model is documented.
* [x] Failure behavior is documented.
* [x] Benchmarks are documented.
* [x] Final demo is documented.

## Reproducibility

* [x] Clean checkout works.
* [x] Clean Composer installation works.
* [x] Docker environment works.
* [x] Demo works from a clean environment.

---

# 51. Final Result

The completed `php-systems-platform` should make it possible to demonstrate the entire chain:

```text
                ┌─────────────────────────┐
                │       HTTP request      │
                └────────────┬────────────┘
                             │
                             ▼
                    ┌────────────────┐
                    │  HTTP Server   │
                    └───────┬────────┘
                            │
                            ▼
                    ┌────────────────┐
                    │  Application   │
                    └───┬────┬────┬──┘
                        │    │    │
              ┌─────────┘    │    └──────────┐
              ▼              ▼               ▼
           Cache          Database          Queue
              │                               │
              │                               ▼
              │                           Worker Pool
              │                            │ │ │ │
              │                            ▼ ▼ ▼ ▼
              │                             Jobs
              │
              └──────────────┐
                             ▼
                         Response

       ┌──────────────────────────────────────────┐
       │ Concurrency / Processes / Memory / IPC   │
       ├──────────────────────────────────────────┤
       │ Backpressure / Retry / Timeout / Failure │
       ├──────────────────────────────────────────┤
       │ Metrics / Tracing / Benchmarking         │
       └──────────────────────────────────────────┘
```

The important outcome is not the amount of code.

The important outcome is that one can look at the final system and clearly see how:

```text
PHP
 ↓
processes
 ↓
IPC
 ↓
concurrency
 ↓
workers
 ↓
networking
 ↓
cache
 ↓
database
 ↓
queues
 ↓
backpressure
 ↓
failure recovery
 ↓
observability
```

fit together into one working system.

That is the actual purpose of `Final Platform`.
