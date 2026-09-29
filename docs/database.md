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

That report is one method (`run()` plus `report()`), not four. `read()` and
`write()` each used to repeat it for the success and the failure case, and the
duplication was a correctness hazard rather than a style one: the two copies
for a given path are the ones that decide whether a span says `ok: false` and
whether `db.errors` moves, and when they drift the result is a trace that
contradicts the metrics. One path means an operation is reported the same way
whichever of the two it took.

## Migrations, and who is allowed to run them at the same time

`Migrator` runs at start-up, which means two `serve` processes coming up
together run it at the same time. The schema work is idempotent, but the seed
data was not: it inserted fixed-key rows and treated a duplicate-key error as
fatal. Two simultaneous starts on an empty data directory meant one of them
crashed - measured before the fix, 5 of 6 concurrent processes died on
`Duplicate value for unique index "PRIMARY" on "customers"`, in
`Migrator::seed()`.

`seed()` now inserts through `insertIfMissing()` and, if the statement still
loses the race, ignores exactly that one outcome - a `ClientException` whose
error code is a constraint violation and whose message names a duplicate
unique value. Anything else still propagates. The distinction is the point: a
duplicate is the expected loser of a race, whereas a genuine schema or
connectivity error arrives under a different code and must not be mistaken for
one, since swallowing it would leave a half-migrated platform that looks
healthy. (The server's "column already exists" is the trap in the other
direction - it arrives as `TABLE_NOT_FOUND`, so the message is the only
signal there.)

With the fix, all 6 of 6 concurrent starts complete.

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