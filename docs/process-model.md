# Process Model

The actual process tree of a running platform, as spawned by `serve` and
`queue:consume` started in the correct order (serve first, then the
consumer, which adopts what serve started).

```text
minidb.php (daemonized, reparented to init)
     │  owns database state, tracked by minidb.pid
     │
serve
 ├── cache.php           serve owns the cache server (a child; no daemon mode)
 ├── worker.php          serve owns the pool Master
 │   ├── worker #1       the Master forks the floor (min 2)
 │   └── worker #2
 │
queue:consume            adopts the pool Master - owns nothing it adopted
 └── forwarder x4        the consumer's own dispatch processes
                         (config queue.consumers, default 4)
```

This is a different shape from the PLAN's single-master example, and it is
deliberate: the platform splits the producer (`serve`) from the consumer
(`queue:consume`) across processes, and the pool Master is owned by `serve`
and *adopted* by the consumer (see `ownership.md`). The consumer does not
start a second pool; it dispatches through the same Master, which is why
there is exactly one `worker.php` Master even though both processes use it.

## Reading it live

The tree is inspectable with standard OS tools. In the container:

```bash
ps -eo pid,ppid,cmd --forest | grep -E "platform.php|worker.php|minidb|cache.php" | grep -v grep
```

or with `pstree`:

```bash
pstree -p <serve-pid>
```

What each line proves:

| Line          | What it proves                                       |
| ------------- | ---------------------------------------------------- |
| `minidb.php` with ppid 1 | the database server daemonized (reparented to init) |
| `cache.php` and `worker.php` under `serve` | serve owns the cache and the pool Master |
| `worker #1`, `worker #2` under `worker.php` | the Master forked its floor (min workers) |
| `queue:consume` running forwarders, no second `worker.php` | the consumer adopted the pool rather than starting one |

## The three states of a live platform

- **serve only** (HTTP + servers + pool, no consumption): the tree above
  minus `queue:consume`. Jobs pile up in the journal; nothing drains them.
- **serve + queue:consume** (the demo and production shape): the full tree.
  The consumer restores the journal and drains what serve publishes.
- **a command owning a throwaway platform** (`load`, `experiments`): the
  same tree, but owned by one short-lived process that stops everything it
  started on every path (see `ownership.md`).

## Why the min workers floor is visible

`bin/worker.php` reads `WORKER_POOL_MIN` (default 2) and forks that many
eagerly at boot - which is why two worker processes appear under the Master
even before any job arrives. The pool grows toward `WORKER_POOL_MAX` under
load and retires idle workers back down, so the floor is the invariant a
`ps` read confirms.

## Correct startup order

`serve` first, *then* `queue:consume`. Starting them simultaneously races
the ownership probes (the database/cache/pool may both "not be running" at
probe time), so the correct sequence is: start `serve`, wait for the HTTP
port to answer, then start `queue:consume` - which is exactly what the demo
and the experiment/load commands do. That ordering is what makes the tree
above deterministic.