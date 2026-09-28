# Memory

The platform's concurrency is process-based, and the memory behavior that
falls out of that is the point of this document - not a bug to hide.

## Fork and copy-on-write

`bin/worker.php` (the pool Master) forks worker processes. A forked child
shares its parent's pages until it writes to them; only then does the kernel
copy the page (copy-on-write). So:

- **Before a worker writes anything**, its RSS mostly *overlaps* the
  parent's - the child has its own bookkeeping, but the big arrays it was
  forked with are shared.
- **After a worker writes to its own copy** of a structure, those pages are
  private to it, and the parent's copy is untouched.

This is why `memory:demo` and `workers:memory` exist: they make the two sides
of that bargain visible instead of asserted.

## What `workers:memory` measures

`php bin/platform.php workers:memory` forks 1, 2, 4 and 8 workers, each
holding a copy of the same `elements`-sized array, and prints total RSS
"before" and "after" the workers write to their copies:

```text
  workers    parent    avg before    avg after    total before    total after
        1      28.1M       17.3M       33.7M         45.4M         61.8M
        8      28.1M       17.3M       33.7M        166.3M        298.1M
```

The numbers that matter are the **deltas between the two totals**. 1 -> 8
workers grew total RSS by 120.9M before any of them wrote anything (eight
processes' bookkeeping), and by 236.2M once each held a private copy - 115.3M
more than adding workers alone accounts for. That gap is seven private copies
of one array that a thread or coroutine pool would only ever have held once.

## The honest caveat

RSS is summed per process here, so the "before" total already double-counts
pages every worker still shares with its parent. That is a known limit of RSS
as a metric, not noise - which is exactly why the *growth between the two
totals*, not either number alone, is what isolates what writing actually
cost. The platform measures the delta and documents the limit rather than
presenting the raw total as if it were private memory.

## Why process-based at all

PHP's threads share one interpreter; real parallelism needs processes. The
pool is the component's native model, and the memory price is the trade the
platform makes explicitly: **cheap parallelism when workers share, expensive
when each worker mutates its own large copy.** The benchmark exists so that
trade is measured, documented, and can be re-measured on another machine
instead of being folklore.