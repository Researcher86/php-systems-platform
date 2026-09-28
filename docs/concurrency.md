# Concurrency

The platform demonstrates three genuinely different ways to do concurrent
work, and the choice between them is the point.

## The three models

| Model       | Mechanism              | Where              |
| ----------- | ---------------------- | ------------------ |
| Sequential  | one process, one order | `SequentialOrderLoader` |
| Forked      | `pcntl_fork()`, one child per part, results via a socket | `ForkedOrderLoader` |
| Pooled      | parts sent to the worker pool as tasks, answered on separate worker processes | `ConcurrentOrderLoader` |

All three load the same "order's whole picture" - the order row, the
customer, the product - and return the same snapshot, so the comparison is
only about the concurrency mechanism.

## Why the numbers move the way they do

Measured in `orders:compare` (see `benchmarks.md`):

```text
  local reads only              with a 50 ms simulated dependency per part
  sequential      0.714 ms      sequential    158.561 ms
  forked          8.526 ms      forked         62.725 ms
  pooled          1.386 ms      pooled         54.940 ms
```

Three local reads are cheaper in one process than handed to three: the
fan-out itself costs (a fork is a process; a pooled task is a socket
round-trip), and when each part takes microseconds, parallelism saves nothing
- it *adds*. The moment a part actually waits, the same three-way overlap
pays: both forked and pooled beat sequential by running the waits beside each
other. Pooled beats forked because it establishes one connection and reuses
it, where a fork pays a process and a connection per load.

The benchmark is runnable with `delay-ms=0` (show the fan-out's own cost) and
with a large delay (show when it pays) - which is the lesson in runnable
form: **concurrency is overhead until the work waits, then it is the whole
difference.**

## The two bargains of the pooled fan-out

`ConcurrentTaskRunner` (used by `GET /parallel`) sends every chunk into the
pool at once and collects, with two shapes:

- `run()` - all-or-fail: one bad chunk fails the whole call, because the
  caller asked for all of them.
- `runWithin()` - degrades: one shared time budget for the group, returns
  only the chunks that made it, keyed by position. That is the number an HTTP
  handler actually has to work with.

The difference is why `run()` and `runWithin()` are two methods and not one
with a flag: "give me everything" and "give me what you can in this time" are
different contracts, and the caller should say which one it means.

## HTTP concurrency

`serve` is a select-loop server, not a thread-per-connection server. One
process multiplexes many connections; a connection that stops sending is
reclaimed by the idle sweep, and one stuck mid-header is reclaimed by the
header sweep (the Slowloris guard, `timeouts` in `README.md`). This is the
component's own model, and the platform adds nothing on top of it - which is
why the HTTP server's `request_timeout`/`header_timeout` config must be
enforced by a periodic sweep, because the select loop would otherwise never
look at a stalled connection again.

## The workers are not threads

The worker pool is process-based: the Master forks worker processes. That is
the cheapest way to get true parallelism in PHP (no interpreter lock to
share), and its price is memory - each worker that writes its own copy of a
large structure pays the copy (see `memory.md`). The platform deliberately
uses the fork/pool model because it is the platform's components' native
model, and it documents the memory price instead of pretending it is not
there.

## Explicit concurrency limits

Every concurrency limit the platform has is a named, overrideable number, not
an accident of the machine:

| Limit                        | Value / bounds                      | Where |
| ---------------------------- | ----------------------------------- | ----- |
| Worker pool floor            | `WORKER_POOL_MIN`, default 2        | `config/platform.php` |
| Worker pool ceiling          | `WORKER_POOL_MAX`, default 16       | `config/platform.php` |
| Queue consumer forwarders    | `queue.consumers`, default 4        | `config/platform.php` |
| Database connections         | `Database::connect(..., 10)`        | `serve` |
| `benchmark` jobs             | 1 .. 5000                           | CLI guard |
| `benchmark` workers          | 1 .. 16                             | CLI guard |
| `load` requests per phase    | 1 .. 20000 (`MAX_REQUESTS`)         | `LoadTestRunner` |
| `load` HTTP concurrency      | 1 .. 64 (default 8 in flight)       | `LoadTestRunner` |
| `load` queue jobs            | 1 .. 5000                           | `LoadTestRunner` |
| `orders:compare` rounds/delay| 1..100 rounds, 1..1000 ms delay     | CLI guard |

The HTTP server itself has no hard concurrent-connection cap - it is a
select loop, so its concurrency is bounded by the idle and header timeouts
that reclaim stalled connections, not by a fixed slot count. That is a
deliberate property of the component's model, and it is why the timeouts are
enforced by a periodic sweep rather than left as configuration.