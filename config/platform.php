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
        'request_timeout' => 5.0,
    ],
    'database' => [
        'host' => '127.0.0.1',
        'port' => 5433,
        'data_dir' => sys_get_temp_dir() . '/php-systems-platform/db',
        'timeout' => 2.0,
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
        'bootstrap_timeout' => 10.0,
        'task_timeout' => 5.0,
        'socket' => '/tmp/php-worker-pool.sock',
    ],
    'jobs' => [
        'idempotency_store' => sys_get_temp_dir() . '/php-systems-platform/idempotency.json',
    ],
];