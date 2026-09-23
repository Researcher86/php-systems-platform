<?php

declare(strict_types=1);

use PhpSystemsPlatform\Workers\WorkerJobs;
use PhpSystemsPlatform\Workers\WorkerTasks;
use PhpWorkerPool\Master\Master;
use PhpWorkerPool\Protocol\Request;
use PhpWorkerPool\Protocol\Response;

require __DIR__ . '/../vendor/autoload.php';

/*
 * The platform's worker-pool Master: one process that owns a pool of forked
 * workers over a unix socket. serve spawns this child whenever no pool
 * answers on the configured socket and stops it again on shutdown; the HTTP
 * control plane reaches it through Workers\ConcurrentTaskRunner, and the
 * queue consumer reaches it through Workers\WorkerManager. Everything the
 * pool does is the component's - this file only decides which tasks its
 * workers run and with what sizing.
 *
 * Two task families live here: the hash tasks (ping/hash_chunk, WorkerTasks)
 * that back GET /parallel, and job.execute (WorkerJobs) that runs queue jobs
 * on a worker process. The pool itself never knows what a task means - it
 * only forks processes and moves Request/Response between them.
 */

$config = require __DIR__ . '/../config/platform.php';
$workers = $config['workers'];

$workerTasks = WorkerTasks::handler();
$workerJobs = new WorkerJobs($config)->handler();

$handler = static function (Request $request) use ($workerJobs, $workerTasks): Response {
    return $request->action === 'job.execute'
        ? $workerJobs($request)
        : $workerTasks($request);
};

$master = new Master(
    socketPath: $workers['socket'],
    minWorkers: 2,
    maxWorkers: 16,
    maxQueueSize: 10_000,
    requestTimeoutSeconds: $workers['task_timeout'],
    handler: $handler,
);

$master->run();
