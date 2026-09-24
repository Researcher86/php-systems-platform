<?php

declare(strict_types=1);

use PhpSystemsPlatform\Workers\CatalogTasks;
use PhpSystemsPlatform\Workers\WorkerJobs;
use PhpSystemsPlatform\Workers\WorkerMemoryTasks;
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
 * Four task families live here: the hash tasks (ping/hash_chunk,
 * WorkerTasks) that back GET /parallel, job.execute (WorkerJobs) that runs
 * queue jobs on a worker process, the catalog.* reads (CatalogTasks) the
 * concurrent order loader fans out, and memory.hold (WorkerMemoryTasks,
 * PLAN Step 16) that lets `workers:memory` measure and grow a worker's own
 * memory. The pool itself never knows what a task means - it only forks
 * processes and moves Request/Response between them.
 */

$config = require __DIR__ . '/../config/platform.php';
$workers = $config['workers'];

// The platform's own launch uses the config's socket and autoscaling bounds
// (min 2, max 16); an environment override lets a benchmark run an isolated
// pool with a fixed number of workers on its own socket.
$socket = getenv('WORKER_POOL_SOCKET') ?: (string) $workers['socket'];
$minWorkers = (int) (getenv('WORKER_POOL_MIN') ?: 2);
$maxWorkers = (int) (getenv('WORKER_POOL_MAX') ?: 16);
$requestTimeout = (float) (getenv('WORKER_POOL_TIMEOUT') ?: $workers['task_timeout']);

// PLAN Step 18's remaining two timeout categories, both the Master's own
// (php-worker-pool's terminateStuckWorkers() - see its docblock for exactly
// what each one bounds): the job execution timeout - a worker held on ONE
// task past this is killed and replaced, the answer to a handler that never
// returns - and the worker lifecycle timeouts - how long a worker may sit
// STARTING without reporting ready, or STOPPING without actually exiting.
// Every one of the three was previously left at the component's own
// default, silently; an isolated test pool overrides the execution one to
// demonstrate the mechanism on a short deadline instead of a 60s wait.
$executionTimeout = (float) (getenv('WORKER_POOL_EXECUTION_TIMEOUT') ?: $workers['execution_timeout']);
$bootstrapTimeout = (float) $workers['bootstrap_timeout'];
$departureTimeout = (float) $workers['departure_timeout'];

$workerTasks = WorkerTasks::handler();
$workerJobs = new WorkerJobs($config)->handler();
$catalogTasks = new CatalogTasks((array) $config['database'])->handler();
// Built once, here, before Master::run() eagerly forks minWorkers workers:
// fork() copies this object into every worker, so each starts with its own
// independent (empty) $held - the same copy-on-write the object's own
// docblock is about, just watched from the outside this time.
$memoryTasks = new WorkerMemoryTasks()->handler();

$handler = static function (Request $request) use ($catalogTasks, $memoryTasks, $workerJobs, $workerTasks): Response {
    if ($request->action === 'job.execute') {
        return $workerJobs($request);
    }

    if (str_starts_with($request->action, 'catalog.')) {
        return $catalogTasks($request);
    }

    return str_starts_with($request->action, 'memory.')
        ? $memoryTasks($request)
        : $workerTasks($request);
};

$master = new Master(
    socketPath: $socket,
    minWorkers: $minWorkers,
    maxWorkers: $maxWorkers,
    maxQueueSize: 10_000,
    requestTimeoutSeconds: $requestTimeout,
    workerExecutionTimeoutSeconds: $executionTimeout,
    workerBootstrapTimeoutSeconds: $bootstrapTimeout,
    workerDepartureTimeoutSeconds: $departureTimeout,
    handler: $handler,
);

$master->run();
