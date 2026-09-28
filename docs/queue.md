# Queue

The queue is a durable append-only journal, and that decision is the *why*
behind most of the queue's shape.

## The journal as the only shared state

`serve` (the producer) and `queue:consume` (the consumer) are different
processes. They cannot share an in-memory queue, so the queue is a file:
`queue.log`, an append-only JSONL journal that every job's lifecycle
appends to. `Producer` appends published jobs; the consumer re-reads the
journal on a short interval and picks up any row it has not seen before; a
completed or failed job is appended as a state change, not edited in place.

Consequences that fall out of "journal as source of truth":

- **Restart is free.** A consumer that died and restarts restores the
  journal and replays READY jobs. The queue is not lost with a process.
- **The consumer must re-sync.** Jobs published by another process while it
  lives are picked up by re-reading the file, which is why `QueueConsumer`
  keeps a "known ids" set instead of trusting one restore.
- **`/queue/status` and `queue:status` are just journal counts.** Depth,
  published, completed, failed, retried are derived from the file, so a
  second process can observe the queue without owning it.

## Producer / consumer split

```text
POST /orders ──► Producer ──► queue.log
queue:consume ──► QueueConsumer ──► JobDispatcher ──► WorkerManager ──► pool
```

The write path enqueues (`order.created`) and answers 201; the consumer owns
the rest. That split is why the API does not slow down with the background
work, and why backpressure (see `backpressure.md`) lives at the write path's
front door - the producer is the only place a queue at capacity can be told.

## The worker manager seam

`WorkerManager` hands a queue Job to the pool as a `job.execute` task and
treats the pool's answer as the job's outcome. A task the pool rejects
becomes a failed attempt, so the queue's retry/failure accounting sees it;
a pool that is gone propagates the connection error the same way - a
delivery that did not happen is not a delivery.

## Job types are registered, not switched

`JobRegistry` is the one place a type string becomes a handler. A type
nobody registered is not a silent skip - it is a failed attempt, exactly
like a malformed payload, so it surfaces through the same retry path. The
platform's jobs: `order.created`, `order.process`, `noop`, `demo.failing`,
`demo.slow`.

## Attempts, retry, and the two kinds of failure

A job carries a max-attempts budget and a retry policy (`FixedDelayRetry`).
Two distinctions make the retry honest:

- **Retryable vs not.** `JobRegistry::shouldRetry()` says a payload that can
  never succeed (a missing `order_id`, a malformed `demo.slow` payload) is
  never retried, no matter how many attempts remain. Retrying it would burn
  the budget on a foregone conclusion; the platform fails it instead.
- **Visibility.** A job in flight at the pool for up to the task timeout
  must not be requeued by a consumer that thinks it was lost, so the
  visibility timeout spans the whole pool round-trip.

## Idempotency

`IdempotencyGuard` deduplicates redeliveries by idempotency key. The demo
shows the at-least-once bargain: a worker may run a job more than once, but
a keyed job's effect happens once. The write path publishes `order.process`
with the key `order.process:<id>` so a redelivery collapses onto the same
effect instead of re-running it.

## Why file-backed beats in-memory for this platform

The components are process-based and the platform runs producer and consumer
apart; a journal is the cheapest structure two processes can agree on, and it
makes the queue durable for free. The measured price is throughput: the
journal serializes writers, which is exactly what `benchmarks.md` reports
(more workers buy less than linear once the journal is the bottleneck). The
platform documents that price instead of hiding it - the queue is a durable
journal first and a throughput engine second.