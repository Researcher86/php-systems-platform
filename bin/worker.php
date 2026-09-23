<?php

declare(strict_types=1);

use PhpSystemsPlatform\Workers\WorkerTasks;
use PhpWorkerPool\Master\Master;

require __DIR__ . '/../vendor/autoload.php';

/*
 * The platform's worker-pool Master: one process that owns a pool of forked
 * workers over a unix socket. serve spawns this child whenever no pool
 * answers on the configured socket and stops it again on shutdown; the HTTP
 * control plane reaches it through Workers\ConcurrentTaskRunner. Everything
 * the pool does is the component's - this file only decides which tasks its
 * workers run (WorkerTasks) and with what sizing.
 */

$config = require __DIR__ . '/../config/platform.php';
$workers = $config['workers'];

$master = new Master(
    socketPath: $workers['socket'],
    minWorkers: 2,
    maxWorkers: 16,
    maxQueueSize: 10_000,
    requestTimeoutSeconds: $workers['task_timeout'],
    handler: WorkerTasks::handler(),
);

$master->run();
