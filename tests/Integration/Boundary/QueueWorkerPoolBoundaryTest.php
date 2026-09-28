<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Tests\Integration\Boundary;

use PhpSystemsPlatform\Tests\Support\PlatformTestStack;
use PhpSystemsPlatform\Workers\WorkerFailureInjector;
use PHPUnit\Framework\TestCase;
use PhpWorkerPool\Protocol\Request as WorkerRequest;
use PhpWorkerPool\Sdk\ServerErrorException;

/**
 * The Queue → Worker Pool boundary, against the running stack.
 *
 * PLAN Step 27 names dispatch, completion, worker failure and replacement.
 * The pool under test is the one serve started for the platform - the same
 * socket the queue consumer forwards jobs to - reached with the component's
 * own client, the way a worker request reaches it in production. What is
 * asserted is the pool's behavior as a system: which process answered, that
 * a dead worker's request fails with the pool's own error and not a timeout,
 * and that a replacement worker takes the dead one's slot.
 */
final class QueueWorkerPoolBoundaryTest extends TestCase
{
    private const float SLEEP_MS = 400.0;

    private static PlatformTestStack $stack;

    public static function setUpBeforeClass(): void
    {
        self::$stack = PlatformTestStack::acquire();
    }

    public static function tearDownAfterClass(): void
    {
        PlatformTestStack::release();
    }

    public function testATaskIsDispatchedToAWorkerAndItsAnswerComesBack(): void
    {
        $pool = self::$stack->pool();

        try {
            self::assertSame(['pong' => true], $pool->call(new WorkerRequest('ping')));
        } finally {
            $pool->close();
        }
    }

    public function testTheAnswerComesFromAWorkerProcessAndNotFromTheCaller(): void
    {
        $pool = self::$stack->pool();

        try {
            $answer = $pool->call(new WorkerRequest('sleep', ['ms' => (int) self::SLEEP_MS]));

            // The task ran on a forked worker, which can only be true if the
            // pool really dispatched it somewhere else: the pid is not this
            // process's.
            self::assertSame((int) self::SLEEP_MS, $answer['ms']);
            self::assertNotSame(getmypid(), (int) $answer['pid']);
        } finally {
            $pool->close();
        }
    }

    public function testSeveralTasksRunAtTheSameTimeOnDifferentWorkers(): void
    {
        $pool = self::$stack->pool();

        try {
            $pending = [];

            for ($i = 0; $i < 4; $i++) {
                $pending[] = $pool->send(new WorkerRequest('sleep', ['ms' => (int) self::SLEEP_MS]));
            }

            $answers = $pool->all(...$pending);
            $pids = array_map(static fn (array $answer): int => (int) $answer['pid'], $answers);

            self::assertCount(4, $pids);

            // Four tasks in flight at once on a pool of four, so the requests
            // cannot all have been served by the same worker one after
            // another. (This is the parallelism claim, so the assertion is
            // "more than one", never "exactly four".)
            self::assertGreaterThan(1, count(array_unique($pids)));
        } finally {
            $pool->close();
        }
    }

    public function testThePoolReportsTheWorkersItIsRunning(): void
    {
        $pool = self::$stack->pool();

        try {
            $workers = $pool->stats();
            $config = self::$stack->config();

            self::assertNotSame([], $workers);

            foreach ($workers as $worker) {
                self::assertArrayHasKey('pid', $worker);
                self::assertArrayHasKey('state', $worker);
            }

            // The workers the platform asked for are the ones the pool is
            // running - the pool is started by serve, from the same config.
            $configured = (int) $config['workers']['count'];

            self::assertLessThanOrEqual($configured, count($workers));
            self::assertGreaterThan(0, count($workers));
        } finally {
            $pool->close();
        }
    }

    public function testAnUnknownTaskIsAnErrorAndThePoolKeepsServing(): void
    {
        $pool = self::$stack->pool();

        try {
            $this->assertServerError($pool, 'no.such.task', 'unknown_action');

            // The pool did not take the request down with the task.
            self::assertSame(['pong' => true], $pool->call(new WorkerRequest('ping')));
        } finally {
            $pool->close();
        }
    }

    public function testACrashedWorkerFailsItsRequestAndIsReplaced(): void
    {
        $pool = self::$stack->pool();

        try {
            $before = $this->pids($pool);

            // The platform's own failure injection, the same object serve's
            // POST /debug/fail-worker route uses: crash a worker on request
            // and watch the pool notice and refill the slot.
            $report = new WorkerFailureInjector($pool)->crashOneWorker();

            self::assertTrue($report['crash_detected'], 'The pool never noticed the crash.');

            // The crashed worker's request failed with the pool's own error -
            // the client heard about the death instead of waiting out the
            // request timeout for an answer that was never coming.
            self::assertSame('worker_crashed', $report['error']);

            self::assertTrue($report['worker_removed']);
            self::assertTrue($report['replacement_started']);

            // The worker that died is the one that is gone, and the pid that
            // replaced it is a pid that was not in the pool before. (Compared
            // by pid rather than by set difference on purpose: the master
            // reaps dead workers in batches, so a pool shared with other
            // suites can refill more than the one worker this crash killed.)
            self::assertContains($report['crashed_pid'], $before);
            self::assertNotContains($report['crashed_pid'], $report['after']);
            self::assertContains($report['replacement_pid'], $report['after']);

            // ... and the pool is the size it was afterwards.
            self::assertCount(count($before), $report['after']);
            self::assertSame(count($before), $report['pool_size']);
        } finally {
            $pool->close();
        }
    }

    public function testThePoolIsFullyUsableAgainAfterAWorkerCrashed(): void
    {
        $pool = self::$stack->pool();

        try {
            new WorkerFailureInjector($pool)->crashOneWorker();

            // Replacement done, and the pool answers real work again - both a
            // master-answered call and one that needs a worker.
            self::assertSame(['pong' => true], $pool->call(new WorkerRequest('ping')));

            $answer = $pool->call(new WorkerRequest('sleep', ['ms' => 10]));

            self::assertNotSame(getmypid(), (int) $answer['pid']);
        } finally {
            $pool->close();
        }
    }

    /**
     * @return list<int>
     */
    private function pids(\PhpWorkerPool\Sdk\WorkerPoolClient $pool): array
    {
        $pids = array_map(static fn (array $worker): int => (int) $worker['pid'], $pool->stats());

        sort($pids);

        return array_values($pids);
    }

    private function assertServerError(
        \PhpWorkerPool\Sdk\WorkerPoolClient $pool,
        string $action,
        string $error,
    ): void {
        try {
            $pool->call(new WorkerRequest($action));
            self::fail(sprintf('The "%s" task should have answered with an error.', $action));
        } catch (ServerErrorException $e) {
            self::assertSame($error, $e->error);
        }
    }
}
