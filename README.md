# PHP Systems Platform

**The final integration project of PHP Systems Lab.**

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
                    └────┬────┬────┬───┘
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
             │ Backpressure / Retry / Timeout / Trace │
             └─────────────────────────────────────────┘
```

---

## Systems Lab Components

The platform integrates concepts and components developed in the previous projects.

| Project                | Role                                           |
| ---------------------- | ---------------------------------------------- |
| `php-concurrency`      | Process-based concurrency, IPC, task execution |
| `php-memory-lab`       | Memory, fork, copy-on-write, RSS experiments   |
| `php-worker-pool`      | Worker lifecycle and process management        |
| `php-mini-http-server` | HTTP networking and request handling           |
| `php-mini-database`    | Database and storage                           |
| `php-mini-cache`       | In-memory caching                              |
| `php-job-queue`        | Asynchronous job processing                    |
| `php-benchmark-lab`    | Performance experiments and measurements       |

The platform is the integration layer connecting these concepts.

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
PHP Systems Platform

HTTP Server ........ OK
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

Start workers.

```bash
php bin/platform.php queue:consume
```

Start the queue consumer.

```bash
php bin/platform.php queue:status
```

Inspect queue state.

```bash
php bin/platform.php status
```

Show the current platform status.

```bash
php bin/platform.php demo
```

Run the complete integration demo.

```bash
php bin/platform.php benchmark
```

Run platform benchmarks.

```bash
php bin/platform.php memory:demo
```

Run memory and copy-on-write experiments.

```bash
php bin/platform.php workers:memory
```

Measure worker process memory.

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

until the configured limit is reached.

The platform then applies an explicit backpressure policy instead of allowing unbounded resource consumption.

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

---

# Observability

The platform exposes basic metrics across all major components.

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

Each request receives a request ID.

Background jobs additionally receive a job ID.

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
Job
    │
 job_id
    ▼
Worker
    │
    ▼
Job execution
```

This makes asynchronous work traceable across process boundaries.

---

# Memory Experiments

The platform also connects the application architecture with PHP's process and memory model.

For example:

```text
Parent Process
      │
     fork
      │
 ┌────┴────┐
 ▼         ▼
Child A   Child B
```

The platform can measure:

* parent RSS
* worker RSS
* total memory
* memory before fork
* memory after fork
* memory after mutation

This demonstrates copy-on-write behavior and the memory trade-offs of process-based concurrency.

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

The exact test commands may evolve with the implementation.

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

The README explains the overall system.

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

This repository is the final integration stage of **PHP Systems Lab**.

The lab progresses from isolated experiments toward a complete system:

```text
Phase 1
Foundations
     │
     ▼
Phase 2
Concurrency & Processes
     │
     ▼
Phase 3
Systems Components
     │
     ▼
Phase 4
Storage / Networking / Async Processing
     │
     ▼
Phase 5
Final Platform
```

The final platform connects the individual experiments into one coherent system.

---

## Status

**Phase 5 — Final Platform**

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
