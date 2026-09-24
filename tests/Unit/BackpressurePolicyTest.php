<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Tests\Unit;

use PhpJobQueue\Persistence\FileStorage;
use PhpJobQueue\Producer\JobFactory;
use PhpJobQueue\Producer\Producer;
use PhpJobQueue\Queue\InMemoryQueue;
use PhpJobQueue\Support\SystemClock;
use PhpSystemsPlatform\Queue\BackpressurePolicy;
use PhpSystemsPlatform\Queue\QueueJournal;
use PHPUnit\Framework\TestCase;

/**
 * PLAN Step 17's policy in isolation: depth against a configured MAX_QUEUE_SIZE,
 * on a scratch journal nothing else touches - no server, no HTTP, just the
 * decision the handler acts on.
 */
final class BackpressurePolicyTest extends TestCase
{
    private string $logPath;

    protected function setUp(): void
    {
        $this->logPath = tempnam(sys_get_temp_dir(), 'backpressure-test-');
        unlink($this->logPath);
    }

    protected function tearDown(): void
    {
        @unlink($this->logPath);
    }

    public function testUnderTheLimitIsNotAtCapacity(): void
    {
        $this->publish(2);

        $decision = new BackpressurePolicy(new QueueJournal($this->logPath), maxSize: 3)->evaluate();

        self::assertSame(2, $decision->depth);
        self::assertSame(3, $decision->maxSize);
        self::assertFalse($decision->atCapacity);
    }

    public function testReachingTheLimitIsAtCapacity(): void
    {
        $this->publish(3);

        $decision = new BackpressurePolicy(new QueueJournal($this->logPath), maxSize: 3)->evaluate();

        self::assertSame(3, $decision->depth);
        self::assertTrue($decision->atCapacity);
    }

    public function testACompletedJobDoesNotCountTowardDepth(): void
    {
        $this->publish(3);

        // Depth counts unsettled work, not everything ever published - the
        // same rule QueueJournal::snapshot() already uses for /queue/status.
        $rows = new QueueJournal($this->logPath)->rows();
        $firstId = array_key_first($rows);
        file_put_contents(
            $this->logPath,
            json_encode(['key' => $firstId, 'data' => array_merge($rows[$firstId], ['state' => 'COMPLETED'])], JSON_THROW_ON_ERROR) . "\n",
            FILE_APPEND,
        );

        $decision = new BackpressurePolicy(new QueueJournal($this->logPath), maxSize: 3)->evaluate();

        self::assertSame(2, $decision->depth);
        self::assertFalse($decision->atCapacity);
    }

    private function publish(int $jobs): void
    {
        $clock = new SystemClock();
        $producer = new Producer(
            new InMemoryQueue($clock, new FileStorage($this->logPath)),
            new JobFactory($clock),
        );

        for ($i = 0; $i < $jobs; $i++) {
            $producer->dispatch('bench.noop');
        }
    }
}
