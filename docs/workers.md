# Workers

The worker pool is `php-worker-pool`: a Master process that forks worker
processes, keeps the pool between a min and a max, and enforces every
lifecycle rule. The platform's `bin/worker.php` is a thin launcher that
registers its task handlers and runs the Master; the platform never owns a
worker process itself - the Master does.

## The worker is a task processor

A worker is not a "queue consumer". It answers tasks over the pool socket:

| Task action      | What runs                                  |
| ---------------- | ------------------------------------------ |
| `job.execute`    | the queue's job, via `WorkerManager` (see `queue.md`) |
| `worker.crash`   | the failure-injection crash task (dev/demo only) |
| `catalog.*`      | database catalog reads                      |
| `memory.*`       | the memory benchmarks                       |
| `*`              | the component's own default task handler    |

The same worker pool serves the HTTP fan-out (`/parallel`, `ConcurrentTaskRunner`)
and the queue (`WorkerManager`), because both are just "send a task, get an
answer". The pool does not know or care which.

## The lifecycle

- `bin/worker.php` reads `WORKER_POOL_MIN`/`WORKER_POOL_MAX` (defaults from
  `config/platform.php`: min 2, max 16) and eagerly forks `minWorkers`.
- The Master answers a task by dispatching it to an idle worker; if none is
  idle it may fork toward the max, and it retires idle workers back toward the
  min.
- A worker that holds one task past the execution timeout is **killed and
  replaced** - the answer to a handler that never returns.
- A worker that sits STARTING without reporting ready, or STOPPING without
  exiting, is reaped by the bootstrap/departure timeouts.

Every one of those deadlines has a distinct meaning and a distinct config key
(`task_timeout`, `execution_timeout`, `bootstrap_timeout`, `departure_timeout`
in `config/platform.php`). The distinction matters: `task_timeout` is the
*client* giving up on waiting for an answer; `execution_timeout` is the
*pool* taking the slot back because the task is never finishing. They are
different bargains, and the platform sets execution well above task so the
pool only fires it when a task is genuinely stuck.

## Crash detection and replacement

The pool's Master tracks its workers. A worker that dies - SIGKILLed, segfaulted,
killed externally - is noticed because the Master's client hears
`worker_crashed` (or a dropped connection). The dead pid is removed and a
replacement is started, so the pool returns to its floor.

`POST /debug/fail-worker` (dev/demo only) drives that from the outside: it
asks the pool to SIGKILL one worker, and the observation is the pid change -
the crashed pid disappears from `stats()` and a fresh pid appears. This is
how `failure:demo`, the `experiments` command, and the E2E suites prove the
mechanism: not by reading the code, but by watching the pool's own worker
list.

## Why the pool is shared and not per-feature

One pool for HTTP fan-out *and* the queue means one set of worker processes,
one floor/ceiling, one timeout policy. A benchmark or command that needs
isolation overrides `WORKER_POOL_*` and uses its own socket - which is how
`benchmark`, `load`, and the crash demos run a throwaway pool without
disturbing a live serve.

## Graceful shutdown

SIGTERM/SIGINT stop the pool the way the consumer stops: no new work, finish
the in-flight task up to the grace period, drain, then exit. `queue:consume`
scans the journal on shutdown for jobs that could have been silently lost,
and exits non-zero if there are any - because a consumer that stopped cannot
claim to have delivered everything it was handed.

The `experiments` and `load` commands own their pools through
`src/Support/OwnedProcess`: SIGTERM, wait for exit, and if the process
ignored SIGTERM, kill it and report a non-zero stop instead of a polite one -
a process that had to be killed did not stop.