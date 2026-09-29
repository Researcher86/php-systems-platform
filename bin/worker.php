<?php

declare(strict_types=1);

use PhpSystemsPlatform\Observability\Trace;
use PhpSystemsPlatform\Workers\CatalogTasks;
use PhpSystemsPlatform\Workers\WorkerFailureTasks;
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
 * Five task families live here: the hash tasks (ping/hash_chunk,
 * WorkerTasks) behind GET /parallel, job.execute (WorkerJobs) that runs
 * queue jobs, the catalog.* reads (CatalogTasks) the concurrent order loader
 * fans out, memory.* (WorkerMemoryTasks) that lets `workers:memory` measure
 * and grow a worker's own memory, and worker.* (WorkerFailureTasks) that
 * crashes a worker on demand in development/demo modes. The pool itself never
 * knows what a task means - it only forks processes and moves
 * Request/Response between them.
 */

$config = require __DIR__ . '/../config/platform.php';
$workers = $config['workers'];

// serve launches the pool with the config's socket and autoscaling bounds
// of 2..16 workers; the environment overrides let a benchmark, the demo or
// an experiment run a fixed-size pool, or an isolated one on its own socket.
$socket = getenv('WORKER_POOL_SOCKET') ?: (string) $workers['socket'];
$minWorkers = (int) (getenv('WORKER_POOL_MIN') ?: 2);
$maxWorkers = (int) (getenv('WORKER_POOL_MAX') ?: 16);
$requestTimeout = (float) (getenv('WORKER_POOL_TIMEOUT') ?: $workers['task_timeout']);

// The Master's own timeouts (enforced by php-worker-pool's
// terminateStuckWorkers()): execution - a worker held on ONE task past this is
// killed and replaced; bootstrap/departure - how long a worker may sit
// STARTING or STOPPING. A test pool overrides the execution one to show the
// mechanism on a short deadline.
$executionTimeout = (float) (getenv('WORKER_POOL_EXECUTION_TIMEOUT') ?: $workers['execution_timeout']);
$bootstrapTimeout = (float) $workers['bootstrap_timeout'];
$departureTimeout = (float) $workers['departure_timeout'];

$workerTasks = WorkerTasks::handler();
// One tracer, inherited by every forked worker: each job.execute span lands
// in the same JSONL journal as serve's spans, so a request's whole chain is
// readable from one place. No trace_store means tracing off.
$trace = new Trace(isset($config['jobs']['trace_store']) ? (string) $config['jobs']['trace_store'] : null);
$workerJobs = new WorkerJobs($config, $trace)->handler();
$catalogTasks = new CatalogTasks((array) $config['database'])->handler();
// Built before Master::run() forks the workers, so each inherits its own
// independent (empty) copy of the held memory.
$memoryTasks = new WorkerMemoryTasks()->handler();
// Armed only in development/demo environments (see config failure_injection).
$failureTasks = new WorkerFailureTasks((bool) $config['failure_injection']['enabled'])->handler();

$handler = static function (Request $request) use ($catalogTasks, $failureTasks, $memoryTasks, $workerJobs, $workerTasks): Response {
    if ($request->action === 'job.execute') {
        return $workerJobs($request);
    }

    if (str_starts_with($request->action, 'worker.')) {
        return $failureTasks($request);
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
