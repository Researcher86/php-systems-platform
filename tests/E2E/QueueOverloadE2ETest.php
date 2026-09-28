<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Tests\E2E;

use PhpSystemsPlatform\Tests\Support\PlatformTestStack;
use PHPUnit\Framework\TestCase;

/**
 * PLAN Step 28's overload scenario, end to end, on a platform configured to
 * be overloaded quickly.
 *
 * Backpressure is only a story worth telling if the queue actually fills up,
 * and the platform's queue is generous on purpose (500 jobs by default) - so
 * this scenario runs on a serve of its own, started with QUEQUE_MAX_SIZE=5
 * and nothing draining it. config/platform.php reads that one value from the
 * environment exactly so an overload can be reproduced without editing the
 * file every other process reads; here the whole platform is the harness's
 * own, so the smaller queue is the platform's real configuration rather than
 * a patched one.
 *
 * What has to be true afterwards: the queue grew to its bound and stopped
 * there, orders past the bound were rejected rather than accepted-and-forgot
 * or accepted-and-blocked, nothing was written for a rejected order, and the
 * platform is still answering - bounded, not broken.
 */
final class QueueOverloadE2ETest extends TestCase
{
    private const MAX_QUEUE = 5;

    private const OVERFLOW = 4;

    private static PlatformTestStack $stack;

    public static function setUpBeforeClass(): void
    {
        self::$stack = PlatformTestStack::startOwn(['QUEUE_MAX_SIZE' => (string) self::MAX_QUEUE]);

        // The journal is append-only and shared by every suite that ran
        // before this one, so the queue is drained to empty first: a bound
        // that is only meaningful against a queue that started empty.
        [$pid, $process] = self::$stack->startConsumer('e2e-overload-drain');

        try {
            self::$stack->waitFor(
                static fn (): bool => (int) self::$stack->journal()->snapshot()['depth'] === 0,
                30.0,
                'The queue never drained to empty before the overload scenario.',
            );
        } finally {
            self::$stack->stopChild($pid, $process);
        }
    }

    public static function tearDownAfterClass(): void
    {
        $exit = self::$stack->shutdown();

        self::assertSame(0, $exit, 'The scenario\'s own serve did not shut down cleanly on SIGTERM.');
    }

    public function testMoreOrdersThanTheQueueCanHoldAreRejectedAndThePlatformKeepsServing(): void
    {
        $publishedBefore = (int) self::$stack->journal()->snapshot()['published'];
        $created = [];
        $rejections = [];

        for ($i = 0; $i < self::MAX_QUEUE + self::OVERFLOW; $i++) {
            [$status, $answer, $headers] = self::$stack->http(
                'POST',
                '/orders',
                json_encode(['customer' => 'E2E Overload ' . $i, 'amount' => '10.00'], JSON_THROW_ON_ERROR),
            );

            if ($status === 201) {
                $created[] = json_decode($answer, true, 512, JSON_THROW_ON_ERROR)['id'];

                continue;
            }

            self::assertSame(429, $status, sprintf('Order %d was answered %d instead of 201 or 429.', $i, $status));

            $rejections[] = [
                'body' => json_decode($answer, true, 512, JSON_THROW_ON_ERROR),
                'retryAfter' => PlatformTestStack::header($headers, 'Retry-After'),
            ];
        }

        // The queue grew to exactly its bound and the write path refused the
        // rest - reject, not block, not silently accept.
        self::assertCount(self::MAX_QUEUE, $created);
        self::assertCount(self::OVERFLOW, $rejections);

        $rejection = $rejections[0];

        self::assertSame('Queue is at capacity.', $rejection['body']['error']);
        self::assertSame(self::MAX_QUEUE, (int) $rejection['body']['queueMaxSize']);
        self::assertSame(self::MAX_QUEUE, (int) $rejection['body']['queueDepth']);
        self::assertSame('1', $rejection['retryAfter']);

        // Bounded: the pending work stopped at the bound, and the rejected
        // orders published nothing - the journal grew by exactly the accepted
        // count, so a 429 left no row, no job and no queue growth behind.
        $snapshot = self::$stack->journal()->snapshot();

        self::assertSame(self::MAX_QUEUE, $snapshot['depth']);
        self::assertSame(
            self::MAX_QUEUE,
            (int) $snapshot['published'] - $publishedBefore,
            'A rejected order still published a job.',
        );

        // Every rejection reported the same bound, and never a depth past it:
        // the queue stopped growing at its limit instead of drifting.
        foreach ($rejections as $rejection) {
            self::assertLessThanOrEqual(self::MAX_QUEUE, (int) $rejection['body']['queueDepth']);
            self::assertSame(self::MAX_QUEUE, (int) $rejection['body']['queueMaxSize']);
        }

        // And the platform is still serving, which is the difference between
        // a bounded system and a broken one.
        [$status] = self::$stack->http('GET', '/health');

        self::assertSame(200, $status);
    }
}
