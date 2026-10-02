# PHP Systems Platform

**[🧪 PHP Systems Lab](https://github.com/Researcher86/php-systems-lab)** · Level 8 of 8 · ← [`php-mini-database`](https://github.com/Researcher86/php-mini-database)

> The final integration project of PHP Systems Lab.

`php-systems-platform` brings together the components developed throughout the lab into a single educational backend system.

The goal is not to build a production framework. The goal is to understand how networking, processes, concurrency, workers, queues, caching, storage, backpressure, failures, and observability interact inside one system.

---

## What this project demonstrates

The platform combines:

* HTTP networking
* application request processing
* process-based concurrency
* inter-process communication
* worker pools
* asynchronous job processing
* queues
* caching
* database storage
* retries and idempotency
* timeouts
* backpressure
* graceful shutdown
* failure recovery
* memory and copy-on-write behavior
* metrics and tracing
* performance benchmarking

The final system provides a practical environment for experimenting with the concepts studied throughout the PHP Systems Lab.

---

## Architecture

```text
                         HTTP Client
                              │
                              ▼
                    ┌───────────────────┐
                    │  HTTP Server      │
                    │                   │
                    │ php-mini-http-    │
                    │ server            │
                    └─────────┬─────────┘
                              │
                              ▼
                    ┌───────────────────┐
                    │   Application     │
                    │                   │
                    │ Routing           │
                    │ Services          │
                    │ Domain logic      │
                    └────┬────┬────┬────┘
                         │    │    │
              ┌──────────┘    │    └────────────┐
              ▼               ▼                 ▼
       ┌─────────────┐ ┌─────────────┐ ┌─────────────┐
       │    Cache    │ │  Database   │ │    Queue    │
       │             │ │             │ │             │
       │ php-mini-   │ │ php-mini-   │ │ php-job-    │
       │ cache       │ │ database    │ │ queue       │
       └─────────────┘ └─────────────┘ └──────┬──────┘
                                              │
                                              ▼
                                      ┌───────────────┐
                                      │ Worker Pool   │
                                      │               │
                                      │ php-worker-   │
                                      │ pool          │
                                      └───────┬───────┘
                                              │
                                  ┌───────────┼───────────┐
                                  ▼           ▼           ▼
                               Worker      Worker      Worker
                                  │           │           │
                                  └───────────┼───────────┘
                                              ▼
                                            Jobs

             ┌─────────────────────────────────────────┐
             │ Concurrency / Memory / IPC / Metrics    │
             │ Backpressure / Retry / Timeout / Trace  │
             └─────────────────────────────────────────┘
```

One high-level picture of the same shape, with the process ownership that
makes it work (see `docs/architecture.md`):

```text
serve           HTTP server + routes, owns database/cache servers + pool Master
queue:consume   owns the queue consumer, adopts the pool Master, drains the journal
bin/worker.php  the pool Master: forks, supervises and replaces worker processes
```

---

## Systems Lab Components

The platform integrates the components developed in the previous levels. Five
of them are Composer dependencies; the first two are concepts the platform
reimplements where it needs them.

| Level | Project | Role | How the platform uses it |
| ----- | ------- | ---- | ------------------------ |
| 1 | [`php-memory-lab`](https://github.com/Researcher86/php-memory-lab) | memory, fork, copy-on-write, RSS | concept — `src/Memory/` |
| 2 | [`php-concurrency`](https://github.com/Researcher86/php-concurrency) | process-based concurrency, IPC | concept — `src/Workers/` |
| 3 | [`php-worker-pool`](https://github.com/Researcher86/php-worker-pool) | worker lifecycle and process management | Composer — `src/Workers/` |
| 4 | [`php-job-queue`](https://github.com/Researcher86/php-job-queue) | asynchronous job processing | Composer — `src/Queue/` |
| 5 | [`php-mini-cache`](https://github.com/Researcher86/php-mini-cache) | in-memory caching | Composer — `src/Cache/` |
| 6 | [`php-mini-http-server`](https://github.com/Researcher86/php-mini-http-server) | HTTP networking and request handling | Composer — `src/Http/`, `src/Application/` |
| 7 | [`php-mini-database`](https://github.com/Researcher86/php-mini-database) | database and storage | Composer — `src/Storage/` |

Performance experiments live in this repository: `src/Benchmarks/` and
[`docs/benchmarks.md`](docs/benchmarks.md).

---

# Core Request Flow

A typical request can follow this path:

```text
HTTP Request
     │
     ▼
HTTP Server
     │
     ▼
Application
     │
     ├──────────────► Cache
     │
     ├──────────────► Database
     │
     └──────────────► Job Queue
                            │
                            ▼
                       Worker Pool
                            │
                            ▼
                           Job
```

For example:

```text
POST /orders
```

can:

1. validate the request
2. create an order in the database
3. invalidate or update the cache
4. publish a background job
5. return an HTTP response
6. allow a worker to process the background job asynchronously

---

# Quick Start

Clone the repository and install dependencies:

```bash
git clone <repository-url>
cd php-systems-platform

composer install
```

Start the required environment:

```bash
docker compose up -d
```

Check the platform:

```bash
php bin/platform.php status
```

---

# Run the Demo

The easiest way to see the entire system working together is:

```bash
php bin/platform.php demo
```

The demo performs an end-to-end scenario:

```text
Start platform
     │
     ▼
Initialize components
     │
     ▼
Create sample orders
     │
     ▼
Publish background jobs
     │
     ▼
Process jobs with workers
     │
     ▼
Inject a controlled failure
     │
     ▼
Recover the worker
     │
     ▼
Retry failed work
     │
     ▼
Show final statistics
     │
     ▼
Graceful shutdown
```

Example output:

```text
Starting platform...
  HTTP server............ OK
  Database............... OK
  Cache.................. OK
  Queue.................. OK
  Workers................ 4

Creating orders...
Created 100 orders

Publishing jobs...
Published 100 jobs

Processing...
Worker #1 processed 23 jobs
Worker #2 processed 28 jobs
Worker #3 processed 23 jobs
Worker #4 processed 26 jobs

Injecting failure...
Worker #1 (pid 4400) exited unexpectedly
  detected 1.9 ms after the crash, replacement within 2.5 ms

Recovering...
Worker #1 (pid 4410) restarted
  pool back at 4/4 workers

Retrying failed jobs...
  published demo.failing (33b4531b-5f46-498b-b80a-c73bd3ccd84a, max 3 attempts)
  attempt 3 -> failed
  => after 3 attempts the job was retired into FAILED (retried=1)

Final statistics...
  HTTP      103 requests, 0 errors
  Cache     100 operations (0 hits, 0 misses)
  Database  123 operations
  Queue     101 published, 100 completed, 1 failed, 1 retried
  Workers   4 active, 0 busy, 4 idle
  Memory    master 30.2M, workers 4.0M each

Graceful shutdown...
  queue consumer......... stopped gracefully
  platform serve......... stopped gracefully
All services stopped.
```

Every line is backed by a live fact, not a canned script: a real `serve`
and `queue:consume` run as the demo's children on the real ports, the 100
orders are real `POST /orders` calls, the per-worker split comes from the
consumer's own `workers.status.json`, the crash is a real
`POST /debug/fail-worker`, the retried job is a real `demo.failing` run to
the end of its attempts budget, and the statistics are a live
`GET /metrics` snapshot (Step 26).

---

# CLI

The platform provides a small CLI for running different parts of the system.

```bash
php bin/platform.php serve
```

Start the HTTP server.

```bash
php bin/platform.php worker
```

Start the worker pool and the queue consumer: the production shape of one
worker node. The pool Master (`bin/worker.php`) runs as this process'
child, and SIGTERM/SIGINT shut the whole node down gracefully - the
consumer stops pulling, finishes and drains what a worker already holds,
then the pool is told to leave (see Graceful shutdown below).

```bash
php bin/platform.php queue:publish order.created '{"order_id":"<id>"}'
php bin/platform.php queue:publish order.process '{"order_id":"<id>"}' "order.process:<id>"
```

Publish one job into the journal-backed queue (also enqueued by every
`POST /orders` while `serve` runs). The optional third argument is the
job's idempotency key (Step 20) — `order.process:<id>` deduplicates a
redelivery like one published by the write path.

```bash
php bin/platform.php queue:consume
```

Start the queue consumer: restores the journal, replays READY jobs, and
keeps picking up jobs published while it runs. SIGTERM/SIGINT stop it
gracefully; the shutdown tail then prints the sequence it ran - no new
work -> no new pulls -> finish executing -> drain workers -> stop workers
-> close resources - scans the journal for jobs that could have been
silently lost, and exits `0` only when there are none (Step 21).

```bash
php bin/platform.php queue:status
```

Inspect queue state (published / depth / completed / failed / retried), the
same counters `GET /queue/status` answers over HTTP.

```bash
php bin/platform.php queue:job <id>
```

Show one job's full metadata - type, state, attempts/max_attempts,
created_at - and its whole attempt history (started_at, completed_at,
last_error per delivery), joined from the queue's own journal and the
platform's own attempt journal (see Retry Policy below for why the second
one exists).

```bash
php bin/platform.php workers:status
```

Show the queue consumer's worker lifecycle (id / pid / state / current job /
completed / failed / started_at), also exposed as `GET /workers`.

```bash
php bin/platform.php benchmark 1000 4
```

Run a measured queue workload (jobs, workers): total time, throughput,
average/p95 latency, queue depth, worker utilization. Comparing 4 vs 8
workers shows the speedup is not linear.

```bash
php bin/platform.php orders:compare 5 50
```

Compare the three ways of loading one order's whole picture (rounds,
simulated external latency per part in ms): sequentially in one process,
with a forked process per independent part, and on the worker pool.

```bash
php bin/platform.php status
```

The simplest way to see the whole platform: every component's state and
headline numbers in one view (Step 25).

```text
PHP Systems Platform
--------------------

HTTP Server
  status:       running
  requests:     131
  errors:       8

Cache
  status:       running
  hits:         1,024
  misses:       310

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
  master RSS:   30.2M
  workers RSS:  4.0M
```

Three sources back the sections: a running serve's `GET /metrics` supplies
the HTTP/cache/database counters and the master process's own RSS (they
only exist inside serve, so they are read over HTTP); a TCP probe of each
server's port and one round-trip to the pool tell *running* from *stopped*;
and the queue journal supplies `depth`/`processed`/`failed` the same way
`GET /queue/status` does. Every source is optional, so a stopped platform is
exactly what the command reports — none of the probes failing is an error.

```bash
php bin/platform.php demo
```

Run the complete integration demo: the whole platform told as one story
(Step 26) — start serve and a queue consumer on the real ports, create 100
orders over HTTP, process their background jobs across the worker pool,
crash one worker and watch the pool replace it, retry a failing job to the
end of its attempts budget, print a live /metrics snapshot, and shut
everything down gracefully. See *Run the Demo* above for the full output.

```bash
php bin/platform.php benchmark
```

Run platform benchmarks.

```bash
php bin/platform.php memory:demo
```

Fork a child over a shared array and print its memory footprint before the
fork, right after it, and after it writes - copy-on-write, measured. Needs
no platform infrastructure (no database, cache, queue or pool).

```bash
php bin/platform.php workers:memory [elements]
```

Run 1, 2, 4 and 8 workers - each its own freshly forked pool - have every
worker hold an array of `elements` ints (default 1,000,000), and compare
total memory before any of them wrote to it against after.

```bash
php bin/platform.php idempotency:demo
```

Show why an at-least-once queue needs an idempotency key: one order
redelivered twice without a guard settles twice (two units of stock), the
same two deliveries under a keyed guard settle once - see Idempotency
below for what the numbers mean. Needs the database server the way
`serve` does; starts one if none answers.

```bash
php bin/platform.php failure:demo
```

Reproduce Step 22's two failure sequences end to end: crash one worker of
a throwaway two-worker pool (the manager detects it, removes it and starts
a replacement - with each phase's timing), then publish a `demo.failing`
job and run the real dispatcher until the journal retires it into the
`FAILED` state after its full attempts budget. The lab arms failure
injection only in development/demo environments; in a production one this
command refuses with `PLATFORM_ENV=dev ...` as the hint.

```bash
php bin/platform.php experiments
```

Run Step 30's five failure/overload experiments, each against its own
platform the command owns and stops: slow workers growing the queue, a
crashed worker being replaced, a dead cache falling through to the
database, a slow database costing every read its configured delay, and a
full queue answering 429 (then the same call once it drains). Everything
the experiments observe is what the platform already reports - `/metrics`,
`/queue/status`, `/workers` and the `X-Cache` header - so they watch the
real platform rather than a second instrumentation of it.

```bash
php bin/platform.php metrics
```

Print the platform's standard metric snapshot as plain text, one
`name value` per line — the same dump a running serve exposes at
`GET /metrics`. The journal's `queue.*` lines and this process's RSS are
always readable; the live worker pool contributes its `workers.*` lines
only when one is answering.

```bash
php bin/platform.php trace <request_id>
```

Print the whole trace chain for one request — the serve's `http.request`
and `db.*` spans plus every worker's `job.execute` span for that
`request_id`, all read back from the shared JSONL trace journal
(`jobs.trace_store`). This is the read side of `#Request and Job Tracing`.

---

# Example API

The platform exposes a small example API.

## Health

```http
GET /health
```

Response:

```json
{
    "status": "ok"
}
```

## Create an order

```http
POST /orders
```

Request body:

```json
{
    "customer": "Ada Lovelace",
    "amount": 19.99,
    "product": "SKU-STANDARD"
}
```

`product` is optional and names a sku in the seeded reference catalog; it
defaults to `SKU-STANDARD`. The sku is what the background processing loads
the product and its stock level by.

Example response:

```json
{
    "id": 123,
    "status": "created"
}
```

Creating an order can trigger asynchronous background processing.

## Get an order

```http
GET /orders/123
```

The request demonstrates the cache-first pattern:

```text
Cache
  │
  ├── HIT ──────► Response
  │
  └── MISS
        │
        ▼
     Database
        │
        ▼
      Cache
        │
        ▼
     Response
```

---

## Fail a worker (development/demo only)

```http
POST /debug/fail-worker
```

The Step 22 controlled failure mode. Crashes one pool worker with SIGKILL
and answers with each phase's evidence, measured off the pool's own
bookkeeping: `worker_crashed` detected → the dead pid removed from the
worker list → a replacement started.

The route exists only in development/demo environments. The lab gates
failure injection on `PLATFORM_ENV`, so a production `serve` has no such
route (404) and a production pool refuses the crash instead of dying:
`{"error":"failure_injection_disabled"}`. Run `failure:demo` to watch the
same sequence without touching a running serve.

---

# Worker Model

Background jobs are processed by multiple worker processes.

```text
                 Queue
                   │
                   ▼
            Worker Manager
                   │
        ┌──────────┼──────────┐
        ▼          ▼          ▼
     Worker #1  Worker #2  Worker #3
        │          │          │
        ▼          ▼          ▼
       Job        Job        Job
```

Workers have an explicit lifecycle:

```text
STARTING
    │
    ▼
  IDLE
    │
    ▼
  BUSY
    │
    ▼
  IDLE
```

During shutdown:

```text
BUSY / IDLE
     │
     ▼
 DRAINING
     │
     ▼
 STOPPING
     │
     ▼
   DEAD
```

This makes process lifecycle and graceful shutdown observable rather than hiding them behind a framework.

---

# Concurrent Database Work

Processing an order needs more than the order: the customer behind it, the
product it names, and that product's stock level. The order has to be read
first - the other three are keyed by what it says - but those three are
independent of each other.

```text
            ┌── customer   (database)
order ──────┼── product    (database)
            └── stock      (database)
```

Three ways of doing that work live in the platform, all answering with
exactly the same snapshot (`Domain\OrderLoader`):

* `Domain\SequentialOrderLoader` - the three reads one after another, in one
  process.
* `Workers\ForkedOrderLoader` - one `pcntl_fork()` per part, results carried
  back over a `socketpair()`, children reaped with `pcntl_waitpid()`. This is
  where the concurrency primitive itself is visible in platform code rather
  than borrowed from a component.
* `Workers\ConcurrentOrderLoader` - the same three parts sent to the worker
  pool, each executed by a worker process that already exists, on its own
  database connection.

`orders:compare` measures all three, twice: once against plain local reads,
and once with a simulated external dependency per part - the kind of
enrichment that waits on something slower than a local table.

```text
Order load comparison: 5 rounds

  local reads only
    sequential      0.713 ms   baseline
    forked          7.507 ms   0.09x
    pooled          0.973 ms   0.73x

  with a 50 ms simulated external dependency per part
    sequential    161.200 ms   baseline
    forked         65.568 ms   2.46x
    pooled         54.898 ms   2.94x
```

Two lessons, not one:

* **Fanning out is not free.** Three sub-millisecond reads get *slower* both
  ways - and forking, which pays a whole process and a fresh database
  connection per part, is an order of magnitude worse than the obvious loop.
  Concurrency has to buy something.
* **What it buys is overlapped waiting.** Once every part waits 50ms, both
  models win, and the pool wins by more: same overlap, but the process
  creation was paid once instead of on every load. That is the difference
  between the raw primitive and a pool - visible here as a number rather than
  asserted.

The database server is a single event loop, so what overlaps is the *waiting*;
concurrency here does not multiply database throughput.

This is also why the background job does not fan out: `OrderProcessJob`
already runs inside a pool worker, and a worker that hands work back to its
own pool competes with the jobs queued behind it. It uses the sequential
loader deliberately.

---

# Asynchronous Processing

The queue provides an asynchronous boundary between the HTTP request and background work.

```text
HTTP Request
     │
     ▼
Application
     │
     ├── Database write
     │
     └── Publish Job
             │
             ▼
           Queue
             │
             ▼
        Worker Pool
             │
             ▼
         Job Handler
```

This allows the platform to demonstrate:

* asynchronous processing
* worker pools
* at-least-once delivery
* retries
* idempotency
* execution timeouts
* queue growth
* backpressure

---

# Backpressure

The platform deliberately allows producers to generate work faster than workers can process it.

```text
Producer rate
     │
     │  > consumer rate
     ▼
   Queue
     │
     ▼
Worker Pool
```

The queue grows:

```text
queue depth ↑
```

until the configured limit is reached: `config/platform.php`'s `queue.max_size`
(default 500).

The platform's policy is **reject**, checked at the one place an HTTP producer
meets the queue - `POST /orders`, before anything is written or enqueued:

```text
POST /orders
     │
     ▼
queue depth >= max_size?
     │
     ├── yes ──► 429, Retry-After: 1, nothing written, nothing enqueued
     │
     └── no ───► create the order, enqueue the job, 201
```

Not block, and not a silent drop: the platform has exactly one HTTP worker
(the component's server is single-threaded), so blocking it on a queue it
cannot itself drain would stall every other request too, and a 201 for work
that was quietly thrown away would tell a caller their order exists when it
does not. A 429 (`Queue\BackpressurePolicy`, which
`Application\Handlers\OrderCreateHandler` checks first) is the caller's own
signal to slow down, with the numbers to explain why:

```json
{"error": "Queue is at capacity.", "queueDepth": 500, "queueMaxSize": 500}
```

Depth is read straight off the same durable journal `GET /queue/status`
already answers with - never an in-memory counter, because the HTTP
process's own queue handle only ever grows (the consumer that actually
drains jobs runs in a different process); the journal is the one place
both sides agree on how much work is outstanding.

To see it trip, lower the limit and cross it:

```bash
QUEUE_MAX_SIZE=3 php bin/platform.php serve &   # small limit for the demo
for i in 1 2 3 4; do
  php bin/platform.php queue:publish order.created '{"order_id":"'"$i"'"}'
done
curl -i -X POST localhost:8080/orders -d '{"customer":"Overflow","amount":1}'
# HTTP/1.1 429
# {"error":"Queue is at capacity.","queueDepth":4,"queueMaxSize":3}
```

This makes it possible to experiment with:

```text
producer rate
worker count
queue capacity
job duration
system throughput
```

and observe their relationship.

---

# Timeouts

Every important boundary has an explicit, configured timeout - none of them
silently inherited from a component default. The plan asks for five, and
they turn out to fall into three genuinely different kinds:

```text
request timeout        how long a CALLER waits for an answer
execution timeout       how long a HANDLER may hold work before it is
                        assumed to be never finishing
lifecycle timeout       how long a WORKER may take to start or to leave
```

| boundary | config key | kind | default |
| --- | --- | --- | --- |
| HTTP connection | `http.request_timeout` | request | 5s |
| HTTP header block (Slowloris) | `http.header_timeout` | request | 5s |
| database query | `database.read_timeout` / `write_timeout` | request | 30s |
| worker round trip | `workers.task_timeout` | request | 5s |
| job execution | `workers.execution_timeout` | execution | 30s |
| worker startup | `workers.bootstrap_timeout` | lifecycle | 10s |
| worker shutdown | `workers.departure_timeout` | lifecycle | 10s |
| job visibility | derived from `workers.task_timeout` | queue | - |

Two of these were bugs, found while wiring the rest explicitly and fixed as
part of this phase:

* `serve` built its `ServerConfig` with a `connectionTimeout` and a
  `headerTimeout`, but never called the component's own
  `Server::closeIdleConnections()` / `closeSlowHeaderReads()` - the sweep
  that actually acts on those numbers. A connection that sent nothing at all
  stayed open forever. It is now swept once a second.
* `bin/worker.php` never set `Master`'s `workerExecutionTimeoutSeconds`,
  `workerBootstrapTimeoutSeconds` or `workerDepartureTimeoutSeconds` -
  php-worker-pool's own model for exactly this distinction (see
  `WorkerPool::terminateStuckWorkers()`). All three ran on the component's
  defaults, unconfigured and invisible. All three are named in
  `config/platform.php` now.

**Request timeout** is about the *caller* giving up - the queue, the HTTP
client, `WorkerPoolClient`, all still waiting for an answer that has not come
back. **Execution timeout** is about the *system* giving up on a handler that
will never return - php-worker-pool's `terminateStuckWorkers()` kills and
replaces a worker that has held one task past `execution_timeout`, verified
here: a worker stuck for 30s under a 1s execution timeout is dead and
replaced well before it, so a fresh request the pool would otherwise have
queued behind it answers immediately. That distinction matters because the
two limits sit on opposite sides of the same wait: `execution_timeout` is
set comfortably above `task_timeout`, so by the time a worker is actually
killed, whoever was waiting on it has already been told `request_timeout` -
the request is not late, it is never finishing.

**Worker lifecycle timeout** is neither: a worker can fail to start, or fail
to leave once told to, with no task in sight - `bootstrap_timeout` and
`departure_timeout` bound those two independently of any request.

**Queue operation timeout** is the job queue's own concept, visibility, and
it is deliberately *derived* rather than independently configured:
`queue:consume` sets it to `workers.task_timeout`, because a job can
legitimately sit in a worker's hands for up to one full round trip - any
shorter and the queue would reclaim a job that is still being (successfully)
worked on, and hand it to someone else too.

The database's own operation timeout (`read_timeout`/`write_timeout`) matches
the component's own defaults exactly - naming them in config changes nothing
about what already ran, it only makes the number a config reader can find
instead of one buried in `php-mini-database`'s own source.

---

# Retry Policy

```text
attempt 1
   ↓ failure
attempt 2
   ↓ failure
attempt 3
   ↓ failure
dead / failed
```

`php-job-queue`'s own retry decision, read from its source rather than
guessed, is exactly `attempts < maxAttempts` - a number fixed once, when a
job is published, with no hook for "and don't bother, this one can never
work." That is not a gap the platform can configure around; it is the whole
of the component's retry logic. So **not every error gets to spend that
budget**: some are rejected before a worker ever sees them.

```text
dispatch → worker executes → throws
     │
     ▼
JobRegistry::shouldRetry(job, exception)
     │
     ├── invalid payload ──► markFailed(), 1 attempt spent, never retried
     │
     └── otherwise ────────► normal attempts < maxAttempts retry-then-fail
```

`php-job-queue`'s `JobDispatcher` takes an optional `shouldRetry` hook,
consulted on every failure before its own `attempts < maxAttempts` check:
`JobRegistry::shouldRetry()` refuses eligibility whenever the job's own
`ValidatesPayload::validate()` (`order.created`, `order.process` - both
reject a payload missing `order_id`) already knows the payload can never
work, cheaply, from the payload alone, no database involved. The job still
runs once - `execute()` validates again and throws - and that one delivery
is the only attempt spent, not the three it would otherwise have wasted:

```text
$ php bin/platform.php queue:job <id>
  type          order.created
  state         FAILED
  attempts      1 / 3

  last attempt
    started   ...
    completed ...
    error     order.created payload is missing order_id.
```

**Retryable which failures, though?** A payload missing `order_id` never
succeeds no matter how many times it runs - permanent. An `order_id`
pointing at a row that does not exist is different: this platform writes the
order before publishing the job, so in normal operation that should never
actually happen, but *proving* it needs a database read a payload check
cannot do - so `shouldRetry()` has no opinion on it, and it is left on the
normal path, retried three times, then failed:

```text
$ php bin/platform.php queue:job <id>
  type          order.created
  state         FAILED
  attempts      3 / 3

  last attempt
    started   ...
    completed ...
    error     Order "..." not found for order.created.
```

`started_at`/`completed_at`/`last_error` are `php-job-queue`'s own
`Job` fields now - not a gap the platform fills anymore. Read directly (not
assumed) before this landed: `Job::toArray()` had `attempts` and
`maxAttempts` but nothing for timing or the last error; `JobDispatcher` now
stamps all three itself around every dispatch and outcome, and the platform
persists them the same way it always persisted everything else about a job.
The one thing that goes with removing the platform's own attempt-journal
duplicate of this: the component tracks the LATEST attempt only, not a
history of every one, so `queue:job` shows the last attempt's timing and
error rather than a full per-retry table.

---

# Failures

Failures are part of the platform rather than exceptional cases hidden from the user.

The system can demonstrate:

* worker crashes
* failed jobs
* retries
* execution timeouts
* queue overload
* slow database operations
* unavailable cache
* graceful shutdown

Example:

```text
Job
 │
 ▼
Worker
 │
 ├── success ───────► completed
 │
 └── failure
       │
       ▼
     retry
       │
       ├── success ─► completed
       │
       └── failure ─► failed
```

Worker failures can be recovered independently from job failures.

---

# Idempotency

Background processing uses an at-least-once execution model.

A worker may fail after performing an operation but before the system records successful completion.

The same job can therefore be executed again.

The platform uses explicit job identity and idempotency mechanisms to demonstrate why:

```text
at-least-once delivery
```

does not mean:

```text
exactly-once execution
```

This is an important property of real asynchronous systems.

Concretely (Step 20): completing an order settles it — one unit of stock off
the shelf — and that settle is the side effect a redelivery must not repeat.
Every enqueued job carries an idempotency key that names the operation
(`order.created:<order id>`, `order.process:<order id>`), checked against the
`PhpJobQueue` IdempotencyGuard, whose append-only store
(`jobs.idempotency_store`) survives a worker restart: a redelivery whose key
the guard already recorded is skipped before any read or write. A crash
between the settle and the record still double-applies, and saying so is the
point — this is deduplication over at-least-once, not exactly-once.

```bash
php bin/platform.php idempotency:demo
```

runs two orders through the same two deliveries twice: once without a guard
(both deliveries settle, stock drops by two) and once keyed (the second
delivery is skipped, stock drops by one), printing the counts.

---

# Observability

The platform exposes a standard metric set across all major components
(Step 23): one shared registry collects what accumulates in-process — the
HTTP, cache and database counters — while the queue, worker and memory
numbers are pulled live from their own sources at read time. The two
halves meet in one snapshot:

```http
GET /metrics
```

```bash
php bin/platform.php metrics
```

Both answer with the same plain-text dump, one `name value` per line,
sorted:

```text
cache.hit 3
db.operations 5
http.requests 12
...
```

Every name below is the contract — a component reports into the registry
or the reporter reads it back, but the string never changes.

### HTTP

```text
http.requests
http.errors
http.request_duration
```

### Cache

```text
cache.hit
cache.miss
cache.operations
```

### Database

```text
db.operations
db.errors
db.operation_duration
```

### Queue

```text
queue.depth
queue.published
queue.completed
queue.failed
queue.retried
```

### Workers

```text
workers.active
workers.busy
workers.idle
workers.failed
worker.task_duration
```

### Memory

```text
process.rss
worker.rss
```

---

# Request and Job Tracing

Every HTTP request receives a request ID (`X-Request-ID` on the way in,
echoed on every response; anything well-formed is kept, otherwise a fresh
`req-…` is generated). The id stays alive inside the request — records
database operation spans — and leaks into the queue: the job a write path
publishes carries the producing request's id in its payload.

Background jobs additionally receive a job ID (the queue's own).

The relationship can therefore be followed:

```text
request_id
    │
    ▼
HTTP request
    │
    ▼
Application
    │
    ▼
Database        ← db.read / db.write spans
    │
    ▼
Job             ← job payload carries request_id
    │
 job_id
    ▼
Worker          ← job.execute span (worker_pid, attempt)
    │
    ▼
Job execution   ← duration, per attempt
```

The worker re-opens the producing request's scope from the payload before
it runs anything, so its execution span and the database calls it makes
stay correlated to the same `request_id`. Each attempt is its own
`job.execute` span, so a retried job is the same `job_id` on consecutive
spans — which answers "which request created the job, which worker ran
it, how long it took, and did it retry?".

All spans land in one append-only JSONL journal (`jobs.trace_store`), and
`php bin/platform.php trace <request_id>` prints a request's whole chain
across processes. Tracing is strictly opt-in at every seam — a process
without a tracer runs exactly as before.

---

# Memory Experiments

The platform also connects the application architecture with PHP's process and
memory model - `php-memory-lab`'s subject, brought in as a re-implemented
measurement rather than a runtime dependency (it is a 32-experiment lab, not
a package; `composer require`-ing it would not fit any more than
`php-concurrency` did in Step 14).

`php bin/platform.php memory:demo` allocates a large array, forks a child
over it, and reads `/proc/<pid>/status` at three moments:

```text
parent allocates the array
      │
     fork()
      │
 ┌────┴─────────────────────┐
 ▼                          ▼
parent keeps its copy   child: snapshot (pages still shared)
                              │
                          child writes to the array
                              │
                          child: snapshot (kernel has now copied
                                            the pages that write touched)
```

Real output:

```text
  before fork    (parent)     php 33.7M   rss 59.7M   shared 0.0M   private memory 41.7M
  after fork     (child)      php 33.7M (+0.0M)   rss 47.0M (-12.7M)   shared 0.0M (+0.0M)   private memory 41.7M (+0.0M)
  after modification (child)  php 65.7M (+32.0M)   rss 79.4M (+32.4M)   shared 0.0M (+0.0M)   private memory 73.7M (+32.0M)
```

The child's `private memory` (`RssAnon`) does not move at all right after
`fork()` - it is still the parent's pages, read-only and shared at the OS
level. Only once the child writes does it grow, by almost exactly the size
of the array: the kernel copied the pages that write touched, and not a byte
more. That growth **is** copy-on-write, measured rather than asserted.

The mechanism is the same fork + `socketpair()` + `pcntl_waitpid()` idiom as
Step 14's `Workers\ForkedOrderLoader` - `Memory\ForkedMemoryDemo` forks one
child, has it report two snapshots of itself over the pipe, and the parent
reaps it before printing the comparison.

## Worker Memory

`php bin/platform.php workers:memory` asks the same question of the platform's
real worker pool: does copy-on-write still help once workers are long-lived
processes doing independent work, not a single one-off child?

php-worker-pool keeps its own worker telemetry internal to the Master, for its
recycling policy, with no wire action that reads it back - so the platform
added one task, `memory.hold` (`Workers\WorkerMemoryTasks`), that lets a
worker measure and grow its own memory and report both, tagged with its own
pid. For each of 1, 2, 4 and 8 workers - a fresh, isolated pool per count, the
same `WORKER_POOL_MIN`/`WORKER_POOL_MAX` override the queue benchmark uses -
every worker is asked to hold an array at the same time, and the platform
sums what came back:

```text
Worker memory comparison: 8 workers, 100,000 elements held each

  workers      parent  avg before   avg after  total before  total after
        1       27.7M       16.8M       19.3M        44.5M        47.0M
        2       27.7M       16.8M       19.3M        61.2M        66.3M
        4       27.7M       16.8M       19.3M        94.9M       105.0M
        8       27.7M       16.8M       19.3M       161.8M       182.0M

Going from 1 to 8 workers grew total RSS by 117.3M before any of them wrote
anything, and by 135.0M once each held its own copy of the same data - 17.7M more
than adding workers alone accounts for. That gap is 7 private copies of
one array a thread or coroutine pool would only ever have held once.
```

Two things worth noticing in that table. `avg before`/`avg after` barely move
with worker count - one worker's own memory does not depend on how many
siblings it has. What *does* scale with worker count is the total, and the
17.7MB gap between how much it grew "before" and "after" is the real cost of
process-based concurrency: seven workers, each privately holding its own copy
of data a thread pool or a coroutine runtime would have kept in one shared
heap. RSS summed across processes already double-counts pages every worker
still shares with its parent (that is a known limit of RSS as a metric, not
noise) - which is exactly why the *delta* between the two totals, not either
one alone, is the number that isolates what writing actually cost.

---

# Benchmarking

The platform includes reproducible performance experiments.

Examples:

```text
HTTP throughput
Cache hit vs cache miss
Database access
Sequential vs concurrent work
1 vs 2 vs 4 vs 8 workers
Queue throughput
Worker failure recovery
Queue overload
Memory usage
```

Benchmarks should always record:

* environment
* workload
* configuration
* concurrency
* number of workers
* dataset size
* latency
* throughput
* memory usage

Raw numbers without workload information are not considered meaningful benchmark results.

Every benchmark is measured and interpreted in [`docs/benchmarks.md`](docs/benchmarks.md) -
environment, configuration, workload, worker count, concurrency, dataset
size, result and interpretation, from one run of the platform's own commands.

---

# Testing

The project uses multiple testing levels.

```text
Unit
  │
  ▼
Integration
  │
  ▼
System
  │
  ▼
End-to-End
  │
  ▼
Failure / Load Experiments
```

Run the test suite with:

```bash
composer test
```

The three commands may evolve with the implementation.

Every push and pull request runs the same three commands on GitHub Actions
(`.github/workflows/ci.yml`), on PHP 8.5 with the extensions the integrated
components need:

```bash
composer test          # all three suites, in order
composer analyse       # PHPStan
composer format:check  # PHP CS Fixer, no changes allowed
```

`composer test` is three suites, and the split exists because they are three
different kinds of feedback at three very different costs:

```bash
composer test:unit          # 131 tests, well under a second - no processes
composer test:integration   # starts real servers, speaks HTTP to them
composer test:e2e           # drives the CLI as a user would
```

While editing, run `composer test:unit`; it is the loop that can keep up with
you. `composer test` still runs all three, in that order, so the full run is
unchanged.

The integration tests start the real database, cache, worker pool and HTTP
server as child processes, so CI exercises the same process model a local
run does - no mocks standing in for a component.

## Integration tests by boundary

Every boundary of the platform is a real component reached over a real
socket, so the integration suites are organised by boundary rather than by
class under test:

```text
tests/Integration/Boundary/
├── HttpApplicationBoundaryTest.php      routing, response handling, errors
├── ApplicationDatabaseBoundaryTest.php  create, read, update, error behavior
├── ApplicationCacheBoundaryTest.php     hit, miss, invalidation
├── ApplicationQueueBoundaryTest.php     publish, consume, failure, retry
└── QueueWorkerPoolBoundaryTest.php      dispatch, completion, failure, replacement
```

`tests/Support/PlatformTestStack.php` is the one place those suites get a
running platform from. It starts the platform's own
`php bin/platform.php serve` - the same process an operator starts, with the
real ports, data directories and wiring - and hands out clients for what that
serve owns. A serve that is already answering is reused; one the harness
started is stopped again when the last suite releases it, by SIGTERM, and the
harness fails the suite if that shutdown was not graceful. Nothing is left
running for the next suite, which is what lets the demo and the other
integration suites keep the ports to themselves.

The queue boundary is the one that cannot be tested in a single process: the
platform publishes from `serve` and consumes in `queue:consume`, sharing only
the append-only journal. Those tests therefore start the real consumer as a
child process, wait for the journal to settle, and stop it with SIGTERM -
judging the graceful shutdown by its exit code.

## End-to-end tests

`tests/E2E/` holds the seven scenarios the platform promises, in the order an
operator meets them. They are not variations on the integration suites: the
integration suites ask a boundary whether it works, these ask the whole
system a question and then read the answer where the platform keeps it - in
the database over a second connection, in the append-only journal another
process owns, in the pool's own statistics, in a process's exit code.

```text
tests/E2E/
├── OrderLifecycleE2ETest.php     create, cache hit/miss, background processing
├── WorkerCrashE2ETest.php        worker crash and replacement, retry and recovery
├── QueueOverloadE2ETest.php      backpressure on a platform with a small queue
└── GracefulShutdownE2ETest.php   SIGTERM while a job is still running
```

Three of them need a platform configured for what they are testing, so the
harness starts one of its own instead of sharing the suite-wide one:

* the overload scenario runs on a serve started with `QUEUE_MAX_SIZE=5`, so
  the queue fills in four extra requests instead of five hundred - the
  platform's own environment override, read by `config/platform.php`, not a
  patched file;
* the shutdown scenario owns its serve, because the claim being tested is that
  the platform stops when it is asked to, and a platform somebody else started
  may not be stopped at all;
* both stop what they started, and the overload and shutdown suites judge that
  teardown by its exit code instead of hiding it in a cleanup.

One job exists only for these tests. `demo.slow` takes the number of seconds
it is given and then succeeds, which is what makes "which worker is holding
this job right now" and "what happens to work in flight when this process is
SIGTERMed" answerable instead of a race against a scheduler. Its duration is
bounded to `(0, 4]` seconds - under the pool's 5s task timeout - because a
job that outlives that timeout fails at the forwarder on every attempt, in a
way that has nothing to do with the question being asked.

The crash scenario sends the platform's own
`POST /debug/fail-worker` and reads the pool's report of what it did; the
retry scenario kills a busy worker with `SIGKILL` from the test, and then
waits for the queue to recover the job and finish it on another worker. Both
assert on the pool's worker list only as far as it is a promise: the pool
autoscales between its configured minimum and maximum, so the pid that
replaced a dead one may itself have been recycled, and what the tests check
is that the dead pid is gone, the pool is still serving, and no job was lost.

## Load tests

`php bin/platform.php load` takes the measurements the plan asks for, all in
one run and against the real platform: the HTTP layer on its own, the read
path with the cache, the same read path without one, and the same background
workload through 1, 2, 4 and 8 workers.

```text
php bin/platform.php load
php bin/platform.php load --requests=2000 --concurrency=16
php bin/platform.php load --jobs=2000 --workers=1,2,4,8,16
php bin/platform.php load --json
```

The run owns the platform. It refuses to start while something else is
answering on the HTTP port rather than measuring a stranger's server, it
wipes the data directory so the numbers come from a cold start, and it stops
every process it started - including on failure, where the child's own output
is left behind because that is the only place a start-up failure explains
itself.

Test B, "the same endpoint without cache", is the one that decides what this
command is allowed to claim. A cache that is *down* is not a platform without
a cache: every read would pay a refused connection and a timeout, and the
report would call that saving money. So `CACHE_ENABLED=0` is a real off
switch - the serve starts no cache server at all, the lookup answers `miss`
without a socket, and `/metrics` reports zero cache operations. Test B runs on
a serve started with it, and the run checks the platform's own `X-Cache`
markers so a mislabelled phase would show up in the report rather than in a
footnote.

The corpus of orders is written straight into the database instead of created
over HTTP, and that is not a shortcut. The create path populates the cache on
write, so an order created through the API is already cached: a "cache miss"
phase built out of API-created orders would have measured hits and called them
misses. Direct inserts also mean the miss phase starts against an empty cache
and costs one write per order instead of two HTTP round trips.

### Measured: 1,000 requests per phase, 8 in flight, 1,000 jobs

```text
Load test (PLAN Step 29)

Environment
  base_url               http://127.0.0.1:8080
  php                    8.5.10
  os                     Linux aarch64
  cpu_cores              12
  memory_limit           128M
  concurrency            8
  requests_per_phase     1000
  corpus_orders          1000
  queue_jobs             1000
  scaling_worker_counts  1,2,4,8
  workers_configured     4
  queue_max_size         500
  cache_enabled_for_b    no (CACHE_ENABLED=0)

Test A - HTTP only, and the read path (Tests B and C)
  test workload                                   requests       rps    avg ms    p95 ms    p99 ms    cpu s peak MB
  A    GET /health (server only)                     1000   26156.9      0.22      0.38      0.44     0.02    30.1
  C1   GET /orders/{id} (first read of each order)    1000    1798.0      3.36      4.87      5.78     0.14    30.1
  C2   GET /orders/{id} (same orders, now cached)    1000   12545.3      0.42      0.63      0.76     0.05    32.1
  B    GET /orders/{id} (CACHE_ENABLED=0)            1000    2241.1      2.68      3.13      3.96     0.10    30.1

  read path, by the platform's own X-Cache marker:
    A    (none)=1000
    C1   miss=1000
    C2   hit=1000
    B    miss=1000

Test D - background jobs through the queue
  1000 jobs, 4 workers: 4941.3 jobs/s, avg 114.40 ms, p95 186.97 ms, peak depth 1000, utilization 61.8%

Test E - worker scaling (same workload, pool resized)
  workers     jobs/s    avg ms    p95 ms      vs 1  utilization
  1           2766.9    195.13    337.41      1.00        83.6%
  2           4057.2    147.91    233.97      1.47        77.9%
  4           4941.3    114.40    186.97      1.79        61.8%
  8           6885.6    110.48    134.03      2.49        73.1%
```

### What those numbers say

The first surprise is that a miss is *more* expensive with a cache than
without one: 1,798 rps against 2,241. A miss is a cache lookup that found
nothing, then the database, then a cache write to remember the answer - three
steps where the cache-less platform takes one. The cache does not make the
first read of an order cheaper; it taxes it, and buys that back on every
later read: the same order again is 12,545 rps, seven times the miss and about
half the cost of a `/health` call that does no work at all.

The second is that a hit is indistinguishable from not touching the database.
C2 at 12,545 rps against A at 26,157 rps means serving a cached order costs
about what routing a request costs, and the remaining half is the cache
socket. The platform's own numbers agree: a phase of 1,000 hits costs the
serve 0.05s of CPU and 32.1MB peak, against 0.14s for the same 1,000 reads
that had to reach the database.

Worker scaling is where the plan's "do not assume linear scaling" earns its
keep. Eight workers are 2.49x the throughput of one, not 8x, and 4x the
workers bought 1.79x. Average job latency flattens out - 195ms at one worker,
110ms at eight - because past two or three workers the queue's own bookkeeping
and the file-backed journal are being serialized against each other, and
utilization stops climbing while throughput keeps creeping. The honest reading
of that table is that this platform's queue is a durable journal first and a
throughput engine second; the workers are not the bottleneck at four.

The queue depth of 1,000 in Test D is the point of that test, not a defect:
every job is published before the pool starts draining, so the depth is the
backlog the platform is asked to absorb, and the `QUEUE_MAX_SIZE=500` the
platform is configured with would have rejected half of them. `load` is
therefore measuring a burst the API would refuse, and it says so by publishing
to the journal directly.

---

# Failure experiments

`php bin/platform.php experiments` runs Step 30's five reproducible
failure/overload scenarios, each against a platform the command owns and
stops. The five are the two failure axes the plan names - the workers and the
dependencies they wait on - crossed with the queue's own capacity:

```text
1. Slow workers      the pool is the bottleneck, so the queue grows
2. Worker crash      the pool manager detects the dead worker and replaces it
3. Cache down        a dead cache is a miss, not a failure: reads fall through
4. Slow database     every read pays the configured 300 ms delay
5. Queue full        the producer gets a 429, then the same call is accepted
```

Each experiment restarts the platform with the one piece of configuration it
needs (a fixed pool, a latency, a small queue), and each is reproducible on
its own. The command owns the platform the way `load` does: it refuses to run
while something else answers on the HTTP port, wipes the data directory, and
stops every process it started on every path.

The `database` config's one new knob is `delay_ms`, set by
`DATABASE_LATENCY_MS` and read only where failure injection is armed - a
delay is a fault, not a setting, so it cannot follow a process into a
deployment that is actually serving. `Database::read`/`write` pay it inside
their timed region, so `db.operation_duration` reports what the caller
actually waited.

---

# Documentation

Detailed documentation is kept separately from this README.

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

The README explains the overall system; each document in `docs/` explains
the *why* behind one part of it:

| Document               | What it is the why of |
| ---------------------- | --------------------- |
| `architecture.md`      | the process split and ownership rules |
| `concurrency.md`       | when the three concurrency models pay |
| `memory.md`            | the copy-on-write price of process workers |
| `workers.md`           | the pool lifecycle and its timeouts |
| `queue.md`             | the journal as the queue's source of truth |
| `cache.md`             | derived state, and down vs disabled |
| `database.md`          | the pool, the timeouts, the slow-database seam |
| `backpressure.md`      | reject-not-block at the producer's door |
| `failure-handling.md`  | the failure modes and their reproduction |
| `failure-matrix.md`    | every failure -> its expected behavior, at a glance |
| `observability.md`     | the reports and one-request-one-chain traces |
| `ownership.md`         | who owns each resource and who stops it |
| `process-model.md`     | the live process tree, readable with `ps`/`pstree` |
| `benchmarks.md`        | every benchmark, measured and interpreted |

`docs/components.md` fixes the surface of each integrated component.

The individual documents explain the experiments and implementation details.

---

# Educational Scope

This project intentionally avoids trying to become a production framework.

It is designed to make systems concepts visible.

The implementation favors:

* simple code
* explicit behavior
* observable state
* reproducible experiments
* understandable process boundaries
* minimal abstractions

over:

* framework complexity
* feature completeness
* production hardening
* maximum performance
* abstraction for abstraction's sake

---

# What You Learn

By completing this project, you should be able to follow a request through a complete backend system:

```text
HTTP
 │
 ▼
Application
 │
 ├── Cache
 │
 ├── Database
 │
 └── Queue
       │
       ▼
   Worker Pool
       │
       ▼
      Job
```

and understand the systems concepts underneath it:

```text
Processes
    │
    ├── fork
    ├── IPC
    └── memory / COW
          │
          ▼
Concurrency
          │
          ▼
Worker Pool
          │
          ▼
Queue
          │
          ├── retry
          ├── idempotency
          └── backpressure
          │
          ▼
Storage / Cache
          │
          ▼
Observability
          │
          ▼
Failure Recovery
```

---

# PHP Systems Lab

This project is part of [**PHP Systems Lab**](https://github.com/Researcher86/php-systems-lab) — a collection of small
educational PHP projects that rebuild the mechanisms behind backend
infrastructure in order to understand them. The recommended order:

| Level | Project | Focus |
| ----- | ------- | ----- |
| 1 | 🧠 [`php-memory-lab`](https://github.com/Researcher86/php-memory-lab) | memory, RSS, fork, copy-on-write, `mmap`, shared memory, FFI |
| 2 | ⚡ [`php-concurrency`](https://github.com/Researcher86/php-concurrency) | processes, IPC, concurrency patterns, event loops, Fibers (course in Russian) |
| 3 | ⚙️ [`php-worker-pool`](https://github.com/Researcher86/php-worker-pool) | persistent master/worker pool, supervision, graceful shutdown |
| 4 | 📬 [`php-job-queue`](https://github.com/Researcher86/php-job-queue) | reliable background jobs: delivery leases, ACK, retries, DLQ |
| 5 | 💾 [`php-mini-cache`](https://github.com/Researcher86/php-mini-cache) | event-driven in-memory server: RESP, pipelining, TTL, Pub/Sub |
| 6 | 🌐 [`php-mini-http-server`](https://github.com/Researcher86/php-mini-http-server) | event-driven HTTP server: parsing, routing, middleware, keep-alive |
| 7 | 🗄️ [`php-mini-database`](https://github.com/Researcher86/php-mini-database) | relational engine: pages, B-trees, SQL, transactions, WAL, recovery |
| **8** | 🏗️ **`php-systems-platform`** (this project) | **integration of the components into one backend platform** |

These are teaching projects, not libraries: a mechanism travels between them
by being read in one and reimplemented in the next. Levels 1–7 do not depend
on each other as packages. Only [`php-systems-platform`](https://github.com/Researcher86/php-systems-platform) requires the
five components (worker pool, job queue, cache, HTTP server, database)
through Composer and runs them together as one system.

## How this project relates

This project is the last level: it depends on the five components as
Composer packages and uses the first two levels as concepts — see
[Systems Lab Components](#systems-lab-components) for what comes from where.

---

## Status

**Level 8 of 8 — Final Platform**

The project is intended to evolve incrementally through the implementation plan.

The initial milestone is not production readiness.

The initial milestone is:

```text
All components work together
and their interactions can be observed,
measured, tested, and intentionally broken.
```

---

## License

MIT.
