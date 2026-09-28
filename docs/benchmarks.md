# Benchmarks

PLAN Step 31: every benchmark must describe its environment, configuration,
workload, worker count, concurrency, dataset size, result and interpretation.
Raw numbers without the workload they came from are not a benchmark, so each
section below carries all eight.

All numbers were produced on one machine, in one sitting, from the platform
at commit `5c73772`. Nothing is simulated: each command owns and stops a real
platform (serve, database, cache, worker pool) the way it would run in
production.

## How to reproduce

```bash
php bin/platform.php benchmark <jobs> <workers>     # queue throughput/latency
php bin/platform.php load                            # HTTP + read path + queue
php bin/platform.php orders:compare <rounds> <delay-ms>
php bin/platform.php workers:memory [<elements>]
```

Run each with no platform already answering: the commands refuse to measure a
stranger's server and stop every process they start.

## Environment

| Field        | Value |
| ------------ | ----- |
| OS           | Linux (aarch64, kernel 7.0.12-linuxkit), containerized |
| PHP          | 8.5.10, `memory_limit` 128M |
| CPU          | 12 cores |
| Memory       | ~9.9 GB total |
| Data dir     | `/tmp/php-systems-platform` (ephemeral) |
| Platform     | commit `5c73772`, `config/platform.php` defaults |

The queue workload below runs the `order.created` handler, which does a
database read through the pool and a cache write - the same work a real
`POST /orders` enqueues. The load tests hit the real HTTP server with real
HTTP clients.

---

# Queue benchmark

`php bin/platform.php benchmark <jobs> <workers>`

| Field         | Value |
| ------------- | ----- |
| Configuration | `config/platform.php` defaults; isolated pool on its own socket, exactly `<workers>` processes |
| Workload      | `order.created` jobs, all published before the pool starts draining (a burst) |
| Concurrency   | `<workers>` pool workers |
| Dataset size  | `<jobs>` jobs in one journal |

## Result: 5,000 jobs

| Workers | Total time | Throughput | Avg latency | p95 latency | Queue depth | Utilization |
| ------: | ---------: | ---------: | ----------: | ----------: | ----------: | ----------: |
| 1       | 4.47 s     | 1118.6/s   | 2005 ms     | 4099 ms     | 5000        | 97.1%       |
| 2       | 2.99 s     | 1675.1/s   | 1391 ms     | 2726 ms     | 5000        | 95.0%       |
| 4       | 1.87 s     | 2671.3/s   | 800 ms      | 1702 ms     | 5000        | 92.3%       |
| 8       | 1.08 s     | 4639.5/s   | 539 ms      | 952 ms      | 5000        | 86.9%       |

Throughput versus one worker: 1.50x, 2.39x, 4.15x.

## Interpretation

Two to four workers buy almost linear throughput (1.5x -> 2.4x) because a
single worker's latency is dominated by the database round-trip the job does,
and two workers overlap those round-trips. Eight workers add only 0.74x over
four - and utilization drops from 97% to 87% - because the shared journal
(one append-only file, one producer) and the pool's own bookkeeping become
the serialized bottleneck. Average latency falls as workers grow because the
burst's queue drains faster; it is not per-job work getting cheaper. `queue
depth = 5000` means every job waited behind the others, so latency here is a
measure of the backlog the platform absorbed, not of one job.

The honest reading: this queue is a durable journal first and a throughput
engine second. The workload caps at `benchmark`'s 5000-job limit by design.

---

# Load tests

`php bin/platform.php load` (defaults: 1000 requests/phase, 8 in flight,
1000 jobs, scaling 1/2/4/8)

| Field         | Value |
| ------------- | ----- |
| Configuration | defaults; serve owned by the run, data dir wiped for a cold start |
| Workload      | A: `GET /health`; C1/C2/B: `GET /orders/{id}`; D/E: 1000 queue jobs |
| Concurrency   | 8 HTTP clients in flight |
| Dataset size  | 1000 orders, 1000 jobs |
| Workers       | 4 configured (D); E walks 1/2/4/8 |

## Result

```text
  test workload                                   requests       rps    avg ms    p95 ms    p99 ms    cpu s peak MB
  A    GET /health (server only)                     1000   25318.4      0.23      0.38      0.57     0.02    30.5
  C1   GET /orders/{id} (first read of each order)    1000    1911.5      3.14      3.64      4.77     0.13    30.5
  C2   GET /orders/{id} (same orders, now cached)    1000   13979.8      0.37      0.48      0.58     0.05    32.5
  B    GET /orders/{id} (CACHE_ENABLED=0)            1000    2112.7      2.95      3.59      4.07     0.12    30.5
```

`X-Cache` markers (the platform's own verdict, checked by the run): A = none,
C1 = 1000 miss, C2 = 1000 hit, B = 1000 miss.

Queue: 1000 jobs, 4 workers -> 5043.1 jobs/s, avg 112.6 ms, p95 184.5 ms,
peak depth 1000, utilization 63.5%.

Scaling: 1 worker 2769.0/s, 2 -> 3418.1/s, 4 -> 5043.1/s, 8 -> 6483.4/s
(vs 1 worker: 1.23x, 1.82x, 2.34x).

## Interpretation

A cache hit is 7.3x the throughput of a miss (C2 13979 vs C1 1911 rps) - a
miss is a lookup, a database read and a refill against three steps for a
hit's one. A platform *without* a cache (B, 2112 rps) beats a platform whose
cache misses (C1, 1911 rps): the miss path pays the cache round-trip and
gains nothing. So the cache pays off exactly when reads repeat - C2 over B
is 6.6x - and a workload of one-shot reads is served faster by the database
alone. That is what the `CACHE_ENABLED=0` off switch is for, and it is a real
off switch, not a cache that has gone down.

Eight workers buy 2.34x, not 8x, with latency flat past four (113 ms at 4,
114 ms at 8): past the first few workers the journal serializes the queue and
utilization stops climbing.

---

# Order loading comparison

`php bin/platform.php orders:compare <rounds> <delay-ms>`

| Field         | Value |
| ------------- | ----- |
| Configuration | defaults; one order, one worker pool on the default socket |
| Workload      | loading one order's full picture (order, customer, product) three ways |
| Concurrency   | sequential vs `fork()`ed vs pooled (three parts) |
| Dataset size  | 1 order, 5 rounds |
| Workers       | pool: the configured pool (4) |

## Result: 5 rounds, 50 ms simulated dependency per part

```text
  local reads only
    sequential      0.714 ms   baseline
    forked          8.526 ms   0.08x
    pooled          1.386 ms   0.52x

  with a 50 ms simulated external dependency per part
    sequential    158.561 ms   baseline
    forked         62.725 ms   2.53x
    pooled         54.940 ms   2.89x
```

## Interpretation

Three local reads are cheaper in one process than handed to three: the
fan-out's overhead (a process and a connection per fork, a pool round-trip
for the pooled variant) exceeds what parallelism saves when each part takes
microseconds. The moment a part actually waits - 50 ms each here - the same
three-way overlap pays: forked 2.53x, pooled 2.89x. Both beat sequential by
overlapping the waits; pooled beats forked because the connection is
established once and reused, where forked pays a process and a connection
per load.

The benchmark is deliberately runnable with `delay-ms=0` to show the
fan-out's own cost, and with a large `delay-ms` to show when it pays.

---

# Worker memory

`php bin/platform.php workers:memory [<elements>]`

| Field         | Value |
| ------------- | ----- |
| Configuration | defaults; pool workers hold one shared `elements`-element array each after the benchmark writes to it |
| Workload      | forking workers that each copy-on-write one array |
| Concurrency   | 1, 2, 4, 8 workers |
| Dataset size  | 1,000,000 elements per worker |
| Workers       | 1, 2, 4, 8 |

## Result

```text
  workers    parent    avg before    avg after    total before    total after
        1      28.1M       17.3M       33.7M         45.4M         61.8M
        2      28.1M       17.2M       33.7M         62.6M         95.5M
        4      28.1M       17.3M       33.8M         97.2M        163.1M
        8      28.1M       17.3M       33.7M        166.3M        298.1M
```

## Interpretation

Going from 1 to 8 workers grew total RSS by 120.9M before any worker wrote
anything, and by 236.2M once each held its own copy of the same array -
115.3M more than adding workers alone accounts for. That gap is 7 private
copies of one array that a thread or coroutine pool would only ever have
held once. The fork model shares pages until a worker writes, then pays the
copy; the platform's pool is cheapest when workers share, and most expensive
when each worker mutates its own copy of a large structure.

(RSS is summed per process, so the "before" total already double-counts
pages still shared with the parent; it is the growth between the two totals
that isolates what writing actually cost.)

---

# Reproducibility

Every number above is the output of the platform's own command, one run, no
trimming. The commands publish to the real journal, run the real pool and
hit the real HTTP server, and each owns its platform: it refuses to start on
a port already being served, and it stops every process it started - so a
re-run starts from the same clean state. Between runs, expect the throughput
numbers to move a few percent (the machine, the data dir, the kernel), and
the *relationships* - hits over misses, 2-4 workers over 1, pooled over
forked once parts wait - to stay the same. Those relationships are the point;
a single run's absolute rps is an artifact of this machine.