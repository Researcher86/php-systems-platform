# Backpressure

`BackpressurePolicy` is the queue's capacity enforced at the producer's front
door: **reject, not block or silently drop.**

## Why it exists

The write path enqueues a job for every order. If the consumer falls behind,
the journal grows without bound - every request adds durable work the
platform is already late on. A producer that is not told "stop" keeps
enqueuing, and the backlog becomes an increasingly expensive promise to keep.
Backpressure turns that into a bounded, explicit answer.

## The decision

```php
$depth = $this->journal->snapshot()['depth'];

return new BackpressureDecision(
    depth: $depth,
    maxSize: $this->maxSize,
    atCapacity: $depth >= $this->maxSize,
);
```

`POST /orders` evaluates the policy **before anything else** - before the
JSON is decoded, before a row is written, before a job is enqueued. An
overloaded queue answers:

```text
HTTP/1.1 429 Too Many Requests
Retry-After: 1
{"error":"Queue is at capacity.","queueDepth":8,"queueMaxSize":5}
```

Nothing was written and nothing was enqueued. The response carries the two
numbers that explain the decision (`queueDepth`, `queueMaxSize`) and a
`Retry-After` the client can actually wait on.

## Why 429 and not a drop

Dropping work would lose orders silently. Blocking (synchronous backpressure)
would tie up the HTTP server. A 429 keeps the queue's contract honest: the
producer is told *when it can try again*, and the client owns the retry.
That is "backpressure is a signal, not a wall" - once the queue drains below
the limit, the same call is accepted again, which the failure experiment
demonstrates by observing a 201 after the earlier 429.

## What the depth means

Depth is the journal's count of jobs that are not completed or failed - the
backlog, including what a worker is running. The policy uses it directly, so
a queue that is full *while jobs are being worked* is still full; capacity is
about outstanding work, not free worker slots.

## Configuration

`QUEUE_MAX_SIZE` (`config/platform.php`, default 500) is the one value worth
overriding without editing the file, because reproducing an overload means
running `serve` with a small one. The `experiments` command runs
`QUEUE_MAX_SIZE=5` with a single worker and a backlog of slow jobs to make
the 429 reproducible on demand.

## The null policy

`application()` registers the policy only when there is a queue log and a
configured limit. A caller with neither gets the unbounded write path back -
the same null-tolerance as `Producer`: fewer pieces means old behavior, not
a crash.