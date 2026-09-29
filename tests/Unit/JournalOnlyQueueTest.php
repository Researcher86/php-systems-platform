<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Tests\Unit;

use PhpJobQueue\Job\JobState;
use PhpJobQueue\Persistence\InMemoryStorage;
use PhpJobQueue\Producer\JobFactory;
use PhpJobQueue\Producer\Producer;
use PhpJobQueue\Support\SystemClock;
use PhpSystemsPlatform\Queue\JournalOnlyQueue;
use PHPUnit\Framework\TestCase;

/**
 * The producer-side queue serve and queue:publish use: every push reaches
 * the journal in the state a consumer's restore expects, and nothing is
 * kept in memory to grow with the process's lifetime.
 */
final class JournalOnlyQueueTest extends TestCase
{
    public function testAPushIsJournaledAsReadyAndNotHeld(): void
    {
        $storage = new InMemoryStorage();
        $queue = new JournalOnlyQueue($storage);
        $clock = new SystemClock();

        $job = new Producer($queue, new JobFactory($clock))->dispatch('order.created', ['order_id' => 'o-1']);

        $rows = $storage->load();
        self::assertSame(JobState::READY->name, $rows[$job->getId()->toString()]['state']);
        self::assertSame(0, $queue->size());
        self::assertNull($queue->pop());
    }

    public function testADelayedPushIsJournaledAsDelayedWithItsDeadline(): void
    {
        $storage = new InMemoryStorage();
        $clock = new SystemClock();
        $before = $clock->now();

        $job = new Producer(new JournalOnlyQueue($storage, $clock), new JobFactory($clock))
            ->dispatch('order.created', delay: 60);

        $row = $storage->load()[$job->getId()->toString()];
        self::assertSame(JobState::DELAYED->name, $row['state']);
        self::assertGreaterThanOrEqual($before + 60, (float) $row['availableAt']);
    }
}
