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

## A request that throws is a 500 and a log line

A handler that throws is caught at the HTTP boundary and answered `500` with a
generic body. The body is deliberately unhelpful: it says the request failed
and nothing about why, because it is going to a client that cannot act on a
stack trace.

The log line next to it used to not exist. The catch reported the status and
discarded the exception, so a handler bug in production produced a bare `500`
in the access log and no other trace of itself anywhere - no message, no
stack, not even which handler was involved. `Application` now takes an
optional `Logger` and reports the throwable's message, class, file, line and
trace, with the method, path and handler named. `serve` wires it to stderr;
tests can pass a recording logger. The two halves are kept apart on purpose -
the client gets the verdict, the operator gets the cause.

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

## Known limits (left in place on purpose)

Three gaps the platform does not close. They are written down instead of
re-architected, because each fix is a mechanism of its own (an outbox, a
transaction, a supervisor), and the point here is that the gap is visible.

### Order create + job publish is a dual write, with no outbox

`OrderService::createOrder()` inserts the order row, *then* appends the
`order.created` job to the queue journal. The two writes go to two different
stores (the database and the journal file) with nothing tying them together.
Doing the insert first means a job never names an order the database does
not have - but a process that dies (or a journal append that throws) between
the two leaves an order in `CREATED` with no job behind it, and nothing ever
notices: no retry, no sweeper, no reconciliation. The textbook fix is a
transactional outbox (write the job into the same database transaction as
the order, relay it to the queue afterwards); the platform has no
transactions and no relay, so the window stays open. Backpressure narrows
nothing here - it is checked before the insert, so a 429 writes neither.

### Product write + `product.index` publish is the same dual write

`ProductService` writes the product row, then publishes `product.index` for
php-mini-search, with nothing tying the two together, exactly like orders.
A process that dies in between leaves the search index one change behind for
that product. Unlike the order gap, this one is healed: the next change to
the product publishes a job that sends its current row, and a full reindex
(`product.reindex`, or `search:reindex`) resends every live product and
removes whatever the index holds that the catalog no longer has. The outbox
that would close the window was weighed and rejected. The mini database
allows one transaction per server, so an outbox transaction around every
product write would block every other write the platform makes.

### `OrderProcessJob` is not atomic, and records its idempotency last

Settling an order is three separate steps against the database, then the
idempotency record:

1. `decrementAvailable($sku)` - a conditional `UPDATE` that takes one unit
2. `updateOrderStatus($orderId, COMPLETED|CANCELLED)`
3. drop the cached order
4. `markProcessed()` - append `order.process:<id>` to the idempotency journal

There is no transaction around 1 and 2, so a crash between them leaves a unit
taken off the shelf for an order still in `CREATED`. And because the record
is written after the effects, a crash anywhere before step 4 means the
redelivered job (delivery is at-least-once) is not recognized as done and
runs again - taking a second unit. The guard closes the *common* duplicate
(a redelivery after the job finished) and leaves this residual window, which
`OrderProcessJob` and `JobContext::markProcessed()` document in the code.

### Stopping `serve` takes the pool away from `queue:consume`

The worker pool is owned by whichever process started it - normally `serve` -
and adopted by `queue:consume` through a client (see `ownership.md`).
Stopping `serve` stops the pool Master it owns, even while a consumer is
still forwarding jobs to it. The consumer is not told and does not start a
replacement: its deliveries fail, each one is a failed attempt that burns
retry budget, and jobs that run out of attempts end `FAILED`. Stop the
consumer first, or restart `serve` (or the consumer, which then starts and
owns a pool of its own) before the budget runs out. The reverse holds too:
if the consumer started the pool, stopping the consumer takes it away from
`serve`'s `/parallel` and concurrent loader.

## The common failure contract

Database, cache, and pool all follow it: **report honestly, let the caller
decide.** A failed query counts as an operation and re-throws; a dead cache
is a bypass, not a swallowed error; a pool that is gone is a failed
delivery. Nothing in the platform pretends a failure did not happen, which is
what makes the failures observable at all.