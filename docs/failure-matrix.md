# Shutdown and Recovery Matrix

Every failure mode the platform can hit, what it is expected to do, how that
is observed, and how it is reproduced. The "why" behind each row lives in
`failure-handling.md`; this is the at-a-glance matrix.

## The matrix

| Failure                    | Expected behavior                          | Observed by                              | Reproduce with |
| -------------------------- | ------------------------------------------ | ---------------------------------------- | -------------- |
| HTTP client disconnects    | Request terminates safely; idle connection reclaimed | serve log (`closed: idle past ...`) | a client that stops mid-request |
| HTTP client stalls mid-header | connection reclaimed by the header sweep (Slowloris guard) | serve log (`header past ...`) | a trickling client |
| Worker crashes             | pool detects, removes dead pid, starts a replacement | `stats()` pid change; `/workers` | `POST /debug/fail-worker`, `failure:demo`, `experiments` |
| Job fails (retryable)      | retry per policy up to `max_attempts`, then `FAILED` | journal `retried`/`failed`; `queue:job` | `demo.failing`, `experiments` |
| Job fails (never succeeds) | failed on the first attempt, never retried | journal `failed`, `attempts=1` | a payload missing `order_id` |
| Job exceeds execution timeout | pool kills the stuck worker, replaces it; the job is a failed/retryable attempt | worker replacement; journal | `WORKER_POOL_EXECUTION_TIMEOUT` on a short deadline |
| Queue is full              | backpressure: `429 Retry-After: 1`, nothing enqueued | the 429 body (`queueDepth`, `queueMaxSize`) | `QUEUE_MAX_SIZE=5`, `experiments` |
| Cache unavailable          | `CacheClientException` -> bypass -> database; still `200 X-Cache: miss` | `X-Cache: miss`, `db.operations` rises | `experiments` (kills the cache mid-run) |
| Database error             | the request/job fails explicitly; connection released | `db.errors`; a 4xx/5xx or a failed attempt | a query against a stopped server |
| Database slow              | every read/write pays the delay; latency and `db.operation_duration` track it; the queue grows | request ms, `db.operation_duration` | `DATABASE_LATENCY_MS=300`, `experiments` |
| Redelivery of a keyed job  | idempotency guard deduplicates; effect happens once | idempotency demo output | `idempotency:demo` |
| SIGTERM / SIGINT on serve  | graceful shutdown: stop accepting, stop owned servers/pool/db, exit 0 | `Shutdown complete.`; exit code 0 | `kill -TERM <serve-pid>` |
| SIGTERM / SIGINT on consumer | graceful: no new pulls, finish in-flight, drain workers, scan journal for lost jobs | shutdown tail; exit 0 only if nothing was lost | `kill -TERM <consume-pid>` |
| SIGTERM ignored           | `OwnedProcess` kills it and reports a non-zero stop - a kill is not a graceful stop | exit code != 0 | a stubborn child in `load`/`experiments` |
| Cold start after a crash  | journal restores READY jobs; queue is not lost with a process | `queue:consume` restore line | kill the consumer, start it again |
| HTTP port already taken  | start-up fails, exit 1, and **nothing the attempt started keeps running** - no orphan pool, cache or db | the three stop lines, then exit 1; `pgrep -f bin/worker.php` empty | a second `serve` on a bound port |
| One cleanup step refuses  | the remaining steps still run; the refusal is reported to stderr | the `[shutdown] could not release ...` line plus the later ones | a pool that answers `refused` when asked to stop |
| `CACHE_ENABLED=0`        | no cache server process at all, on either end of the platform | no `bin/cache.php` in `pgrep` | `CACHE_ENABLED=0` with a consumer running |
| Two platforms migrate at once | both succeed; the loser's seed insert is ignored, not fatal | both processes reach "migrate ok" | two `serve` processes starting on an empty data dir |

## The two recovery axes

The matrix separates the two things that can fail, because they recover
independently:

- **Workers** - the pool replaces a dead or stuck worker; the platform's
  *capacity* recovers.
- **Jobs** - the queue retries, fails or deduplicates; the platform's *work*
  gets a verdict.

A worker crash is not a job failure (the job is retried through the queue);
a job failure is not a worker failure (the worker keeps running). Confusing
them is the most common way to misread this matrix.

## What is deliberately not in it

There is no "recover the queue by dropping it" row: the journal is durable by
design, so recovery is restore, not restart. And there is no "fail silently"
row anywhere: database, cache and pool all report honestly and let the
caller decide - a failure that cannot be observed is not recoverable.