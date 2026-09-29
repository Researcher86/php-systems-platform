# Cache

The cache is derived state, and every design decision follows from that.

## The database is the source of truth

`CacheService` only ever stores an order's JSON, keyed by id. It holds a
*dependent copy* of what the database owns. A hit skips the repository; a
miss reads the authoritative row and refills the cache. Nothing in the cache
is ever authoritative, which is why a cache failure is never a data-loss
event - it is a performance event.

## The read path

`GET /orders/{id}` in `OrderReadHandler`:

1. `cache->getOrder($id)` - hit: `200 X-Cache: hit`, no database.
2. miss: database read -> refill cache -> `200 X-Cache: miss`.
3. cache *unreachable* (`CacheClientException`): bypass -> database read ->
   `200 X-Cache: miss` with the bypass counter incremented.

`X-Cache` is the platform's own marker, and it exists so the platform can
prove which branch it took - `load` and the failure experiments assert on it
instead of trusting that the code is right.

## Why "cache down" and "no cache" are different

Two failures of the same word:

- **Cache down** = the tier exists but refused or timed out. Every read pays
  a failed connection plus the database read, and the failure is counted as
  a bypass.
- **Cache disabled** (`CACHE_ENABLED=0`) = no tier at all. Every lookup
  answers `miss` without a socket, a timeout or a fallback.

They measure different things. `load` Test B needs "the read path without a
cache tier" - a number, not a failure - so it uses the off switch, and the
difference is the point of `cache.md`: measuring a down cache would be
measuring the cost of a failed connection, which is a different question.

`CACHE_ENABLED=0` now reaches the consumer as well. `queue:consume` used to
start the cache server unconditionally, so a platform configured with no cache
tier still spawned one for the worker side, and a test asserting "no cache
process exists" would have found it. Both ends of the platform now honour the
same switch: no tier, no cache process, and the consumer's lookups count as
misses without a socket.

## One miss, counted once

`CacheService::getOrder()` has three distinct reasons to answer "no payload" -
no cache tier, nothing stored, and something stored that does not parse back
into an object. Each of them counted the miss itself, so the definition of a
miss was three copies of three statements, and they had to stay equal by hand.
They now go through `recordMiss()`, and the hit path's two increments sit
beside it, so "hits + misses = lookups" is a property of one method rather
than of three call sites agreeing.

## Why a miss is slower than no cache at all

Measured in `benchmarks.md`: a cache *miss* (1911 rps) is slower than a
platform with no cache (2113 rps). The miss path pays the lookup, the
database read, *and* the refill - three steps against a no-cache platform's
one. The cache pays off exactly when reads repeat: a hit is 7.3x a miss and
6.6x no-cache. That is why the cache is a read-path accelerator for repeated
reads, and why a workload of one-shot reads is served faster by the database
alone.

## Invalidation

Entries carry a TTL (`ORDER_TTL_SECONDS = 60`) long enough that a demo read
hits, short enough that a stale entry ages out. The write path is
write-through: `POST /orders` populates the cache on write, so a freshly
created order is immediately readable from cache. An update invalidates so
the next read refills from the authoritative row.

## The failure contract

Cache-client failures surface as `CacheClientException`, and degrading is the
*handler's* decision, never the service's. `CacheService` does not swallow
errors and pretend the cache answered; it reports what happened and lets the
caller (read handler, write handler, queue job) choose the fallback. A dead
cache is a miss, not a failure - the read still answers 200 from the
database, which is exactly what the failure experiment demonstrates by
killing the cache server mid-run.