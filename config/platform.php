<?php

/*
 * Global platform defaults. Every value here is a default that a CLI flag,
 * an environment variable, or one of the demo/benchmark commands is allowed
 * to override - the file exists so the operational shape of the system is
 * visible in one place instead of being scattered across commands.
 */

// Not `getenv(...) ?: '1'`: '0' is falsy, so the elvis form would turn
// CACHE_ENABLED=0 back into 1.
$cacheEnabled = getenv('CACHE_ENABLED');
$platformEnv = strtolower(trim((string) (getenv('PLATFORM_ENV') ?: 'dev')));
$faultInjection = in_array($platformEnv, ['dev', 'development', 'demo', 'test'], true);

return [
    'http' => [
        'host' => '127.0.0.1',
        'port' => 8080,
        // request_timeout closes a connection idle this long (nothing sent
        // or received). header_timeout is the Slowloris guard: a connection
        // still mid-header-block this long is closed, since a trickling
        // client is never idle but never finishes a header either.
        'request_timeout' => 5.0,
        'header_timeout' => 5.0,
    ],
    'database' => [
        'host' => '127.0.0.1',
        'port' => 5433,
        'data_dir' => sys_get_temp_dir() . '/php-systems-platform/db',
        'timeout' => 2.0,
        // How long one query may take once connected (`timeout` above is
        // the connect). Same values as the component's ClientConfig defaults,
        // named here to make them visible.
        'read_timeout' => 30.0,
        'write_timeout' => 30.0,
        // The slow-database experiment's artificial delay per read and
        // write, in milliseconds. A delay is a fault, not a setting, so it is
        // gated like failure injection and ignored outside dev/demo/test.
        'delay_ms' => $faultInjection ? (float) (getenv('DATABASE_LATENCY_MS') ?: 0.0) : 0.0,
    ],
    'cache' => [
        'host' => '127.0.0.1',
        'port' => 6380,
        'data_dir' => sys_get_temp_dir() . '/php-systems-platform/cache',
        'timeout' => 2.0,
        // CACHE_ENABLED=0 means no cache tier at all (no server started,
        // every lookup a miss) rather than a cache that is down, whose
        // refused connections would be a cost of their own in the "without
        // cache" benchmark. Off values are listed, not cast: (bool) 'false'
        // is true.
        'enabled' => !in_array(
            strtolower(trim($cacheEnabled === false ? '1' : $cacheEnabled)),
            ['0', 'false', 'no', 'off'],
            true,
        ),
    ],
    'queue' => [
        'data_dir' => sys_get_temp_dir() . '/php-systems-platform/queue',
        // The backpressure limit; overridable so an overload can be
        // reproduced by running `serve` with a small one.
        'max_size' => (int) (getenv('QUEUE_MAX_SIZE') ?: 500),
        'timeout' => 2.0,
        'max_attempts' => 3,
        // Whole seconds: php-job-queue's RetryPolicy::nextDelay() returns an
        // int, so a fractional value here would be truncated to 0 and every
        // retry would fire on the next tick, spending max_attempts at once.
        'retry_delay' => 1,
        'consumers' => 4,
    ],
    'workers' => [
        'data_dir' => sys_get_temp_dir() . '/php-systems-platform/worker',
        'count' => 4,
        // Worker lifecycle timeouts: how long a worker may sit STARTING
        // without reporting ready, or STOPPING without exiting.
        'bootstrap_timeout' => 10.0,
        'departure_timeout' => 10.0,
        // How long a WorkerPoolClient waits for one task's answer (the
        // pool's "request timeout", renamed so it is not confused with the
        // HTTP one); used by both the Master and every client.
        'task_timeout' => 5.0,
        // How long a worker may hold ONE task before the pool kills and
        // replaces it. task_timeout is the client giving up; this is the pool
        // taking the slot back, deliberately well above it.
        'execution_timeout' => 30.0,
        'socket' => '/tmp/php-worker-pool.sock',
    ],
    'jobs' => [
        // Append-only JSONL journal of IdempotencyGuard's recorded
        // operations (one line per record, hence .log).
        'idempotency_store' => sys_get_temp_dir() . '/php-systems-platform/idempotency.log',
        // Append-only JSONL journal every process (serve and each pool
        // worker) writes its spans to; read by `bin/platform.php trace <id>`.
        'trace_store' => sys_get_temp_dir() . '/php-systems-platform/trace.log',
    ],
    // The worker.crash pool task, POST /debug/fail-worker and the
    // demo.failing job are armed only when PLATFORM_ENV is dev/demo/test, so
    // a route that kills a worker cannot exist in a real deployment. The
    // same switch gates database.delay_ms.
    'failure_injection' => [
        'enabled' => $faultInjection,
    ],
];