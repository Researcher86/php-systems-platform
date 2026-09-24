<?php

/*
 * Global platform defaults. Every value here is a default that a CLI flag,
 * an environment variable, or one of the demo/benchmark commands is allowed
 * to override - the file exists so the operational shape of the system is
 * visible in one place instead of being scattered across commands.
 */
return [
    'http' => [
        'host' => '127.0.0.1',
        'port' => 8080,
        // PLAN Step 18's HTTP request timeout, in two parts. request_timeout
        // closes a connection idle this long - connected, nothing sent or
        // received (see Server::closeIdleConnections()). header_timeout is
        // narrower: a connection mid-header-block this long, the Slowloris
        // guard (Server::closeSlowHeaderReads()) - a trickling client stays
        // alive under the idle check because it IS sending bytes, just never
        // enough to finish a header.
        'request_timeout' => 5.0,
        'header_timeout' => 5.0,
    ],
    'database' => [
        'host' => '127.0.0.1',
        'port' => 5433,
        'data_dir' => sys_get_temp_dir() . '/php-systems-platform/db',
        'timeout' => 2.0,
        // The database operation timeout (PLAN Step 18): how long one query
        // may take once a connection exists, separate from connecting to it
        // in the first place (`timeout`, above). Matches the component's own
        // ClientConfig defaults, so naming them here changes nothing about
        // what already ran - it only makes the number visible.
        'read_timeout' => 30.0,
        'write_timeout' => 30.0,
    ],
    'cache' => [
        'host' => '127.0.0.1',
        'port' => 6380,
        'data_dir' => sys_get_temp_dir() . '/php-systems-platform/cache',
        'timeout' => 2.0,
    ],
    'queue' => [
        'data_dir' => sys_get_temp_dir() . '/php-systems-platform/queue',
        // The MAX_QUEUE_SIZE Step 17's backpressure policy checks - the one
        // value here worth overriding without editing this file, since
        // reproducing an overload means running `serve` with a small one.
        'max_size' => (int) (getenv('QUEUE_MAX_SIZE') ?: 500),
        'timeout' => 2.0,
        'max_attempts' => 3,
        'retry_delay' => 0.1,
        'consumers' => 4,
    ],
    'workers' => [
        'data_dir' => sys_get_temp_dir() . '/php-systems-platform/worker',
        'count' => 4,
        // Worker lifecycle timeouts (PLAN Step 18): how long a worker may
        // sit STARTING without reporting ready, and how long one told to
        // leave may sit STOPPING without actually exiting. Neither is about
        // a task - a worker can fail its lifecycle with no task in sight.
        'bootstrap_timeout' => 10.0,
        'departure_timeout' => 10.0,
        // The worker operation timeout: how long WorkerPoolClient waits for
        // one task's answer (php-worker-pool's own "request timeout" -
        // named task_timeout here so it is never confused with the platform's
        // HTTP request timeout above), fed to both ends of that wait -
        // Master's requestTimeoutSeconds and every WorkerPoolClient's own.
        'task_timeout' => 5.0,
        // The job execution timeout: how long a worker may hold ONE task
        // before the pool kills and replaces it - the answer to a handler
        // that never returns. Distinct from task_timeout (that one is about
        // the CLIENT giving up on waiting; this one is about the POOL taking
        // the slot back), and deliberately well above it - by the time this
        // fires, the task is not late, it is never finishing.
        'execution_timeout' => 30.0,
        'socket' => '/tmp/php-worker-pool.sock',
    ],
    'jobs' => [
        'idempotency_store' => sys_get_temp_dir() . '/php-systems-platform/idempotency.json',
    ],
];