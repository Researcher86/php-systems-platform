# Observability

The platform is observable through its own reports, not through a second
instrumentation. Three views, each answering a different question, and one
trace system that connects them across processes.

## The metric snapshot: `GET /metrics` and `metrics`

`MetricsReporter` merges three sources into one snapshot:

1. the shared `MetricsRegistry` counters/gauges/durations, which `serve`
   hands to the database and cache at construction so every component
   reports into the same registry;
2. live sources read at snapshot time - the queue journal (`queue.*`) and the
   worker pool (`workers.*`);
3. this process's memory (`process.rss`).

Groups:

| Group        | Examples                                  | Tells you |
| ------------ | ----------------------------------------- | --------- |
| `http.*`     | `http.requests`, `http.request_duration`  | server load |
| `cache.*`    | `cache.hit`, `cache.miss`, `cache.operations` | read path effectiveness |
| `db.*`       | `db.operations`, `db.errors`, `db.operation_duration` | database load, including the slow-database delay |
| `queue.*`    | `queue.depth`, `queue.completed`, `queue.retried` | backlog and progress |
| `workers.*`  | `workers.active`, `workers.busy`, `workers.idle`, `worker.task_duration` | pool health |
| `process.*`  | `process.rss`                             | serve memory |

Durations are stored as sum+count and snapshot as the average - so
`db.operation_duration` is the average the caller waited, which is exactly
what the slow-database experiment reads.

## The queue view: `/queue/status`

Depth, published, completed, failed, retried - derived from the journal on
read, so any process can observe the queue without owning it. This is how a
command that is *not* the consumer (the `experiments` runner, the demos)
watches a backlog form and drain.

## The worker view: `/workers`

The consumer's `WorkerRegistry` snapshot: each worker's id, pid, state,
current job, and completed/failed counters. A pid change means the worker
process was replaced - which is how crash detection is *observed* rather
than asserted. The route exists when the consumer writes the file; the pool
itself is watched through the SDK's `stats()` where there is no consumer.

## Tracing: one request, one chain

`Trace` writes spans to a shared JSONL journal (`jobs.trace_store`). The
serve side opens a scope per HTTP request and records `http.request`,
`db.*`, `cache.*` spans. The write path leaks the producing request's
`request_id` into the job's payload, so a queue worker re-opens the *same*
trace scope and records its `job.execute` span under it. Result: one logical
request is one trace chain across processes, and `trace <request_id>` prints
the whole chain - the serve's spans plus every worker's - from one file.

This is why the spans carry `meta.ok`: a failed query still records its span,
so a trace shows the failure in place rather than a gap.

`db.read` and `db.write` also report through one shared path
(`Database::run()` / `Database::report()`). They used to carry that
bookkeeping themselves, twice each - a success block and a failure block that
re-throws - so the four copies could disagree: a span reporting `ok: false`
next to metrics reporting a success is a report about an operation that never
happened, and telling those two apart is exactly the job a trace exists for.

## Bounded in memory, complete on disk

`Trace` keeps the most recent spans in memory for the request-scoped
inspection, and writes every span to the JSONL journal as it happens. The
in-memory window is capped at `Trace::DEFAULT_MEMORY_SPANS` (1000, overridable
via the constructor) - the oldest spans are dropped from the array once the
window is full.

The cap is a memory bound, not a data loss. The journal is append-only and
written per span, so `trace <request_id>` still answers from the complete
history, and a chain longer than 1000 spans - a large load test, a queue
draining hundreds of jobs - is intact on disk even though the in-process
window no longer holds it. The eviction drops the span that is farthest from
the current request's chain first, which is what the window is for: answering
"what just happened to this request" without an unbounded array in a
long-running `serve`.

`WorkerRegistry` is bounded the same way, for the same reason: it is held for
the lifetime of a serve, so `resolvedAt` entries - the records of workers that
have already finished - are pruned after a TTL (300 s by default) instead of
accumulating one entry per completed job for as long as the process lives. The
`resolvedCount` total is not pruned, because it is a counter and not a set.

## Why observability is opt-in at the seam

The database and cache take optional metrics/trace. Without them they behave
identically; with them they report. That is the platform's wiring philosophy
in one sentence: **observability is the wiring's decision, never the
component's.** `serve` always wires it, which is why the demo and the load
tests read real numbers instead of instrumentation the tests themselves
added.

## The experiments read the same reports

`experiments` observes slow workers via `/queue/status`, the worker crash via
the pool's `stats()`, the dead cache via `X-Cache` and `db.operations`, the
slow database via `db.operation_duration`, and queue fullness via the 429
body. All five use the platform's own reporting - the failure experiments
watch the real platform rather than a second copy of it.