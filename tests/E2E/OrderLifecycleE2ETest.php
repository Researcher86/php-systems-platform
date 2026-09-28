<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Tests\E2E;

use PhpSystemsPlatform\Queue\Jobs\OrderCreatedJob;
use PhpSystemsPlatform\Tests\Support\PlatformTestStack;
use PHPUnit\Framework\TestCase;

/**
 * PLAN Step 28's first three scenarios, as one order's whole life.
 *
 * A scenario here is not a class under test - it is a story that has to
 * arrive: an order created over HTTP has to exist in the database, publish
 * the job the queue then runs, and come back out of the cache the second
 * time it is asked for. Each fact is read where the platform itself keeps
 * it - the row over a second connection with the repository's own SQL, the
 * job in the append-only journal, the cache decision in the X-Cache header
 * of the answer - so a green run means the chain works, not that a test and
 * a handler shared a variable.
 *
 * The one boundary no single process can cross is the queue, so background
 * processing is watched across two: a real `php bin/platform.php
 * queue:consume` child consumes what the HTTP process published, and is
 * stopped with SIGTERM and judged by its exit code, exactly as an operator
 * would stop it.
 */
final class OrderLifecycleE2ETest extends TestCase
{
    private const COLUMNS = 'id, customer, amount, product, status, created_at, updated_at';

    private static PlatformTestStack $stack;

    public static function setUpBeforeClass(): void
    {
        self::$stack = PlatformTestStack::acquire();
    }

    public static function tearDownAfterClass(): void
    {
        PlatformTestStack::release();
    }

    /**
     * Scenario 1 - create order: POST /orders, and the answer, the row and
     * the job all have to agree about the same order.
     */
    public function testAnOrderCreatedOverHttpIsInTheDatabaseTheQueueAndTheAnswer(): void
    {
        $before = self::$stack->journal()->snapshot();

        [$status, $answer, $headers] = self::$stack->http(
            'POST',
            '/orders',
            '{"customer":"E2E Alan Turing","amount":"31.50"}',
        );

        self::assertSame(201, $status);

        $order = json_decode($answer, true, 512, JSON_THROW_ON_ERROR);

        // The answer: a 201 that says what was created and where it lives.
        self::assertSame('created', $order['status']);
        self::assertSame('31.50', $order['amount']);
        self::assertSame('/orders/' . $order['id'], PlatformTestStack::header($headers, 'Location'));

        // The row, read over a connection of this test's own - not the one
        // the handler wrote with, so the write really landed in the platform's
        // database and not in a cache of the request.
        $rows = self::$stack->database()->read(
            'SELECT ' . self::COLUMNS . ' FROM orders WHERE id = ?',
            [$order['id']],
        );

        self::assertCount(1, $rows);
        self::assertSame('E2E Alan Turing', $rows[0]['customer']);
        self::assertSame('created', $rows[0]['status']);

        // The job: the write path published work for the row it just wrote,
        // into the journal a consumer will restore from, and nothing else.
        $jobs = $this->jobsFor($order['id']);

        self::assertCount(1, $jobs);
        self::assertSame(OrderCreatedJob::TYPE, $jobs[0]['type']);
        self::assertContains($jobs[0]['state'], ['CREATED', 'DELAYED', 'READY']);
        self::assertSame($before['published'] + 1, self::$stack->journal()->snapshot()['published']);
    }

    /**
     * Scenario 2 - read cached order: two GETs of the same order, the first
     * a miss and the second a hit.
     *
     * The write path's populate-on-write would make the very first read a
     * hit, so the entry is emptied the way a real invalidation empties it:
     * the HTTP update path, which drops the cached copy of an order it
     * changed. From there the two reads are the ones a client makes.
     */
    public function testReadingAnOrderTwiceMissesTheCacheAndThenHitsIt(): void
    {
        $order = $this->createOrder('E2E Cache', '44.00');
        $id = (string) $order['id'];

        [$status] = self::$stack->http('PUT', '/orders/' . $id, '{"status":"cancelled"}');
        self::assertSame(200, $status);

        [$status, $first, $headers] = self::$stack->http('GET', '/orders/' . $id);

        self::assertSame(200, $status);
        self::assertSame('miss', PlatformTestStack::header($headers, 'X-Cache'));
        self::assertSame('cancelled', json_decode($first, true, 512, JSON_THROW_ON_ERROR)['status']);

        [$status, $second, $headers] = self::$stack->http('GET', '/orders/' . $id);

        self::assertSame(200, $status);
        self::assertSame('hit', PlatformTestStack::header($headers, 'X-Cache'));
        self::assertSame('cancelled', json_decode($second, true, 512, JSON_THROW_ON_ERROR)['status']);

        // And the hit was a real one: the entry the second read served is in
        // the cache server, which the harness reads over the same socket.
        self::assertSame('cancelled', self::$stack->cache()->getOrder($id)['status'] ?? null);
    }

    /**
     * Scenario 3 - background processing: POST /orders, and a real worker
     * receives the published job and finishes it.
     */
    public function testAnOrderCreatedOverHttpIsProcessedByARealWorker(): void
    {
        $this->withConsumer(function (): void {
            $handledBefore = $this->poolHandledRequests();
            $order = $this->createOrder('E2E Background', '55.00');
            $jobs = $this->jobsFor((string) $order['id']);

            self::assertNotSame([], $jobs, 'The write path published no job for the order.');

            $jobId = (string) $jobs[0]['id'];

            self::$stack->waitFor(
                fn (): bool => (string) ($this->jobById($jobId)['state'] ?? '') === 'COMPLETED',
                30.0,
                'The order.created job was never completed by a consumer.',
            );

            $settled = $this->jobById($jobId);

            // The job ran once and succeeded: one attempt, no error, and it
            // really was the type the write path publishes.
            self::assertSame(OrderCreatedJob::TYPE, $settled['type']);
            self::assertSame(1, (int) $settled['attempts']);
            self::assertNull($settled['lastError']);

            // A pool worker received the job, told by the pool's own
            // per-worker counter of requests handled - the component's
            // bookkeeping, not an inference from timing.
            self::assertGreaterThan(
                $handledBefore,
                $this->poolHandledRequests(),
                'No pool worker handled the job.execute task.',
            );

            // And the consumer's own lifecycle file attributes finished work
            // to a forwarder, so the job was not completed by anything else.
            $rows = self::$stack->workerRows();

            self::assertNotSame([], $rows, 'The consumer wrote no worker lifecycle.');
            self::assertGreaterThanOrEqual(
                1,
                array_sum(array_map(static fn (array $row): int => (int) $row['tasks_completed'], $rows)),
                'No forwarder recorded a completed task.',
            );
        });
    }

    /**
     * @param callable(): void $body
     */
    private function withConsumer(callable $body): void
    {
        [$pid, $process] = self::$stack->startConsumer('e2e-lifecycle');

        try {
            $body();
        } finally {
            $exit = self::$stack->stopChild($pid, $process);

            self::assertSame(0, $exit, 'The queue consumer did not shut down cleanly on SIGTERM.');
        }
    }

    private function poolHandledRequests(): int
    {
        return array_sum(array_map(
            static fn (array $row): int => (int) ($row['handledRequests'] ?? 0),
            self::$stack->pool()->stats(),
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function jobsFor(string $orderId): array
    {
        $found = [];

        foreach (self::$stack->journal()->rows() as $id => $row) {
            if (($row['payload']['order_id'] ?? null) === $orderId) {
                $found[] = ['id' => $id] + $row;
            }
        }

        return $found;
    }

    /**
     * @return array<string, mixed>
     */
    private function jobById(string $jobId): array
    {
        return self::$stack->journal()->rows()[$jobId] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    private function createOrder(string $customer, string $amount): array
    {
        [$status, $answer] = self::$stack->http(
            'POST',
            '/orders',
            json_encode(['customer' => $customer, 'amount' => $amount], JSON_THROW_ON_ERROR),
        );

        self::assertSame(201, $status);

        return json_decode($answer, true, 512, JSON_THROW_ON_ERROR);
    }
}
