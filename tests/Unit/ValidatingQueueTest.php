<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Tests\Unit;

use PhpJobQueue\Job\JobState;
use PhpJobQueue\Persistence\FileStorage;
use PhpJobQueue\Producer\JobFactory;
use PhpJobQueue\Producer\Producer;
use PhpJobQueue\Queue\InMemoryQueue;
use PhpJobQueue\Support\SystemClock;
use PhpSystemsPlatform\Queue\ValidatingQueue;
use PHPUnit\Framework\TestCase;

/**
 * PLAN Step 19's "do not retry every possible error": the one lever the
 * queue component actually gives the platform is choosing not to dispatch a
 * job at all. php-job-queue's own retry decision is `attempts < maxAttempts`
 * with no per-error hook - checked directly in JobDispatcher's source - so
 * a job the platform already knows can never succeed is failed here,
 * before it ever reaches a worker, instead of being retried three times
 * for nothing.
 */
final class ValidatingQueueTest extends TestCase
{
    private string $logPath;

    protected function setUp(): void
    {
        $this->logPath = tempnam(sys_get_temp_dir(), 'validating-queue-test-');
        unlink($this->logPath);
    }

    protected function tearDown(): void
    {
        @unlink($this->logPath);
    }

    public function testAJobWhoseTypeHasNoValidatorPassesThroughUnexamined(): void
    {
        $queue = $this->queueWith('bench.noop', []);

        $popped = $queue->pop();

        self::assertNotNull($popped);
        self::assertSame(JobState::READY, $popped->getState());
    }

    public function testAValidPayloadPassesThrough(): void
    {
        $queue = $this->queueWith('order.created', ['order_id' => 'abc']);

        $popped = $queue->pop();

        self::assertNotNull($popped);
        self::assertSame(JobState::READY, $popped->getState());
    }

    public function testAnInvalidPayloadIsFailedImmediatelyAndNeverPopped(): void
    {
        $queue = $this->queueWith('order.created', []);

        self::assertNull($queue->pop());

        $rows = new FileStorage($this->logPath)->load();
        $row = array_values($rows)[0];
        self::assertSame('FAILED', $row['state']);
        // One delivery consumed, not the full budget - the honest cost of
        // the one attempt it takes to notice a payload can never work.
        self::assertSame(1, $row['attempts']);
    }

    public function testAnInvalidJobDoesNotBlockTheValidOnesBehindIt(): void
    {
        $clock = new SystemClock();
        $storage = new FileStorage($this->logPath);
        $producer = new Producer(new InMemoryQueue($clock, $storage), new JobFactory($clock));

        $producer->dispatch('order.created', []);
        $producer->dispatch('order.created', ['order_id' => 'abc']);

        $queue = new ValidatingQueue(
            \PhpJobQueue\Queue\InMemoryQueue::restoreFromStorage($storage, $clock),
            $storage,
        );

        $popped = $queue->pop();

        self::assertNotNull($popped);
        self::assertSame('abc', $popped->getPayload()['order_id']);
    }

    private function queueWith(string $type, array $payload): ValidatingQueue
    {
        $clock = new SystemClock();
        $storage = new FileStorage($this->logPath);
        $producer = new Producer(new InMemoryQueue($clock, $storage), new JobFactory($clock));
        $producer->dispatch($type, $payload);

        return new ValidatingQueue(
            InMemoryQueue::restoreFromStorage($storage, $clock),
            $storage,
        );
    }
}
