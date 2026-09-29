# Resource Ownership

Who owns what, who stops what, and how ownership is proven. This is the
full treatment of the ownership rules summarized in `architecture.md` -
process ownership and resource ownership are central to this lab, so they
get a document of their own.

## The ownership map

| Resource            | Owner                          | How ownership is proven / enforced |
| ------------------- | ------------------------------ | ---------------------------------- |
| HTTP sockets        | `serve` (the `Server`)         | the serve process holds the listening socket |
| Request lifecycle   | `serve` (the `Application`)    | a trace scope is opened per request and closed with it |
| Database state      | the mini-db daemon             | `minidb.pid` pid file; data files under the db data dir |
| Connection pool (db)| `serve`                        | opened by `Database::connect`, closed in `serve()`'s shutdown |
| Cached state        | the cache server process       | the cache's in-memory store, snapshotted to `cache.snapshot` |
| Cache client        | whoever started the cache      | a child handle (no daemon mode, no pid file) |
| Worker-pool Master  | `serve` (or the command that started it) | a child process handle |
| Worker processes    | the pool Master                | worker pids in the Master's `stats()` |
| Pending jobs        | the queue's contract           | the append-only journal; producer appends, consumer drains |
| Trace journal       | appended by serve and workers  | shared JSONL; no single owner, append-only by contract |
| One job's execution | the worker running it          | the pool's task timeout bounds it |
| Data directories    | the process that created them  | commands wipe them for a cold start and refuse to measure a stranger's server |

## Three rules that make ownership unambiguous

1. **A process stops only what it started.** The cache server is the sharpest
   example: it has no daemon mode, so it is a child of whoever started it.
   `serve` adopts an already-running cache and will not stop it on the way
   out (`stopCacheServer()` is a no-op when there is no handle). This is why
   a command that must kill the cache mid-run - the failure experiments -
   starts the cache server itself: it needs the handle.

2. **A pid file is authoritative; the port is ground truth.** The database
   server daemonizes, so `minidb.pid` says who owns it. But a stale pid file
   from a crashed process lies, so `ensureDatabaseServer` also probes the
   port: only when *neither* the pid file nor the port says a server is
   running may it start one and own it. Otherwise two processes would each
   think they own the same server, and the second one would stop the first's
   infrastructure on the way out.

3. **The worker pool is owned once and adopted freely.** `serve` starts the
   Master; `queue:consume` and `ConcurrentTaskRunner` adopt the running pool
   through clients. The adopter never stops the pool. A benchmark or command
   that needs isolation starts its own Master on its own socket
   (`WORKER_POOL_*` overrides), which is the only way to get a throwaway pool
   without touching a live serve.

## Who stops what

| Stopper | What it stops |
| ------- | ------------- |
| `serve` SIGTERM/SIGINT | HTTP server, its db connection pool, the db daemon it owns, the cache it owns, the pool Master it started |
| `queue:consume` | the consumer's own loop; drains workers through the pool's client |
| the pool Master | its worker processes, on its own shutdown |
| `OwnedProcess` (commands: `load`, `experiments`, demos) | every child it started - SIGTERM, wait for exit, kill if it ignores SIGTERM; a non-zero stop says it was killed, not that it stopped politely |

`serve`'s shutdown is the model, and it is `ShutdownStack`
(`src/Support/ShutdownStack.php`): close the db pool, then stop the pool, the
cache, the database - on every path (success, error, signal), because every
path runs one stack rather than a sequence of hand-written `if ($owned)`
statements. That indirection is the point. The old inline version gated each
stop on its own ownership flag, and the bug it had was not any one flag being
wrong: it was that a path which *threw* before reaching the stops skipped all
of them. An occupied port did exactly that - `serve` printed
`Could not start HTTP server`, returned, and left the worker pool, the cache
server and the database daemon running. A flag cannot protect a path that never
reaches the flag.

The stack adds three properties the inline version had to get right by hand
every time:

- **LIFO.** Steps run newest first, so resources are released in the reverse
  of the order they were acquired, and registering a new cleanup means
  remembering one more `push`, not reordering a block of stops.
- **Isolation.** A cleanup step that throws is reported to stderr and the
  stack continues. A database that refuses to stop must not prevent the
  worker pool from stopping.
- **Idempotence.** `run()` drains the stack and returns the same result on
  every later call, so a signal arriving mid-shutdown, or a `finally` running
  after an explicit stop, cannot double-stop anything.

## The graceful-stop contract

A process that was SIGTERMed is expected to stop itself; one that ignores
SIGTERM and has to be killed did not stop. `OwnedProcess` reports that
difference in the exit code, and `queue:consume` reports it in its shutdown
tail: it scans the journal for jobs that could have been silently lost and
exits non-zero if any exist - because a consumer that stopped cannot claim it
delivered everything it was handed.

## Commands that own a whole platform

`load`, `experiments`, and the demos own their platform the same way `serve`
does, but as a short-lived owner: they refuse to start while something else
answers on the HTTP port, wipe the data directory for a cold start, start the
serve (and consumer) they need, and stop everything they started - including
on failure, where the child's own output is left behind because that is the
only place a start-up failure explains itself.