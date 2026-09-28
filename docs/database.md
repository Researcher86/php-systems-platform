# Database

The platform's one seam over `php-mini-database` is `src/Storage/Database.php`:
a small, fixed-size pool of connections and two shaped operations - `read`
rows, `write` rows. The SQL stays in the repositories; the component's types
never leak past this class.

## The pool

A `ConnectionPool` of up to 10 connections reconnects a stale connection
between requests. The platform never holds a connection open across the
whole process; it acquires one per operation and releases it in `finally`,
so a failed query cannot leak the connection. This is why concurrent reads
can overlap: the pool, not the process, is the concurrency.

## Timeouts, and why there are three of them

`config/platform.php` names three different deadlines that a naive design
would call all "database timeout":

| Key            | Bounds                          |
| -------------- | ------------------------------- |
| `timeout`      | connecting to the server in the first place |
| `read_timeout` / `write_timeout` | one query's runtime once a connection exists (default 30s) |
| `delay_ms`     | a deliberate whole-platform delay (dev/demo only, see below) |

The distinction between connecting and querying matters because a server
that accepts TCP but never answers is a different failure from one that is
unreachable. Naming them separately in the config makes the number visible
instead of silently inheriting the component's default.

## Observability is the wiring's decision

`Database` carries an optional `MetricsRegistry` and `Trace`. With them, every
read/write reports `db.operations`, `db.errors` (a failed query still counts
the operation) and `db.operation_duration`, and records a `db.read`/`db.write`
span. Without them, the class behaves exactly the same - the seams exist so
`serve` can attach observability, not so the class depends on it.

## The slow-database seam

`DATABASE_LATENCY_MS` (config `delay_ms`) makes every read and every write
pretend to take that long - the platform's way of asking "what does this
platform do when the database is slow?" A delay is a fault, not a setting, so
it is honored only where failure injection is armed (dev/demo/test) and only
when asked for by name. The delay sits *inside* the timed region, so
`db.operation_duration` reports what the caller actually waited - a
measurement that excluded the slowness would measure nothing.

The measured answer (see `benchmarks.md`, and the `experiments` command):
request latency tracks the delay (a 300ms delay is a 305ms read), the
`db.operation_duration` metric reports it, and the queue behind the slow
operations grows - workers hold their connections while they wait.

## Migration

`Migrator` applies the schema once (idempotently) when a serve or command
starts. It is how the catalog (products, inventory, customers) and the
`orders` table come to exist on a cold data directory, and the migration
upgrade test proves an old schema is upgraded in place rather than rebuilt.

## Failure behavior

A failed query still counts as an operation (`db.operations` and `db.errors`
both move) and re-throws, so the caller decides the fallback. The connection
is released either way. The write path's database error surfaces as a 400/500
at the handler, and the queue job's database error is a failed attempt that
retries - the database's failure policy is "report honestly, let the caller
decide", the same contract as the cache.