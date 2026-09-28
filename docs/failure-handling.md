# Failure Handling

Failures are part of the platform, not exceptional cases hidden from the
user. Every failure mode has a mechanism, a way to observe it, and a way to
reproduce it - and the two axes the platform cares about are *workers* (does
the pool stay healthy?) and *jobs* (does the work get done or get a verdict?).

## The five failure modes

| Failure              | Mechanism                                  | Observed by |
| -------------------- | ------------------------------------------ | ----------- |
| Worker crash         | pool Master detects, removes dead pid, starts a replacement | `/workers`, `stats()`, the pid change |
| Job keeps failing    | retry policy burns the attempt budget, then `FAILED` (dead) | journal's `failed`/`retried`, `queue:job` |
| Execution timeout    | pool kills a worker that held one task past `execution_timeout` | worker replacement |
| Cache unavailable    | `CacheClientException` -> bypass -> database | `X-Cache: miss`, `db.operations` |
| Database slow        | every read/write pays `DATABASE_LATENCY_MS` | request latency, `db.operation_duration` |

## Worker failures are independent of job failures

A worker crash is not a job failure. The pool replaces the worker; the jobs
it was holding are retried through the queue's own accounting. The two
mechanisms are deliberately separate: `WorkerManager` treats the pool's
answer as the job's outcome, and a dead pool (delivery that did not happen)
is a failed attempt that retries - the job is not lost with the worker.

## Job failure: the two kinds of "no"

`JobRegistry::shouldRetry()` separates the retryable from the foregone:

- **Retryable** (a database hiccup, a worker crash mid-job): attempt, wait
  the retry delay, try again, up to `max_attempts`.
- **Never going to succeed** (payload missing `order_id`, a malformed
  `demo.slow`): failed on the first attempt, never retried, because burning
  the budget on a foregone conclusion is wasted work and a misleading
  `retried` count.

A failing job dies `FAILED` (dead state) after its full budget, and
`queue:job <id>` shows the whole attempt history - the evidence, not just the
verdict.

## Controlled reproduction

Failure injection is a first-class, but gated, feature:

- `PLATFORM_ENV` (dev/demo/test) arms `worker.crash`, `POST
  /debug/fail-worker`, `demo.failing`, and the database delay. A production
  environment disarms all of it without code changes - a route that kills a
  worker cannot exist next to a deployment that is actually serving.
- `failure:demo` reproduces the worker-crash sequence and the failing-job
  sequence end to end.
- `experiments` reproduces all five failure modes against a platform it owns
  and stops.

The gating is why the failure routes are *absent* rather than disabled in
production: an endpoint that answers 404 is harder to argue with than one
that answers "I could but I won't".

## Graceful shutdown

SIGTERM/SIGINT on the consumer: stop pulling, finish what a worker already
holds up to the grace period, drain, stop workers, close resources - then
scan the journal for jobs that could have been silently lost and exit
non-zero if any. A process that had to be killed is reported as killed
(`OwnedProcess` returns a non-zero stop), because a graceful stop and a kill
are different claims.

## The common failure contract

Database, cache, and pool all follow it: **report honestly, let the caller
decide.** A failed query counts as an operation and re-throws; a dead cache
is a bypass, not a swallowed error; a pool that is gone is a failed
delivery. Nothing in the platform pretends a failure did not happen, which is
what makes the failures observable at all.