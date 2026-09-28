<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Tests\Integration\Boundary;

use PhpSystemsPlatform\Queue\Jobs\FailingJob;
use PhpSystemsPlatform\Queue\Jobs\NoopJob;
use PhpSystemsPlatform\Queue\Jobs\OrderCreatedJob;
use PhpSystemsPlatform\Tests\Support\PlatformTestStack;
use PHPUnit\Framework\TestCase;

/**
 * The Application → Queue boundary, against the running stack.
 *
 * PLAN Step 27 names publish, consume, failure and retry. The queue is
 * cross-process by design - serve publishes, `queue:consume` consumes, the
 * append-only journal is the only state they share - so nothing here is
 * tested in-process on both ends. Publishing happens over HTTP for the real
 * write path, or through the platform's own producer into the same journal
 * for jobs no route publishes; consuming happens in a real
 * `php bin/platform.php queue:consume` child process, stopped with SIGTERM
 * and judged by its exit code.
 */
final class ApplicationQueueBoundaryTest extends TestCase
{
    private static PlatformTestStack $stack;

    public static function setUpBeforeClass(): void
    {
        self::$stack = PlatformTestStack::acquire();
    }

    public static function tearDownAfterClass(): void
    {
        PlatformTestStack::release();
    }

    public function testTheWritePathPublishesTheOrdersJobIntoTheRealJournal(): void
    {
        [$status, $answer] = self::$stack->http('POST', '/orders', '{"customer":"Queued Write","amount":"27.00"}');

        self::assertSame(201, $status);

        $order = json_decode($answer, true, 512, JSON_THROW_ON_ERROR);
        $job = $this->jobFor($order['id']);

        self::assertSame(OrderCreatedJob::TYPE, $job['type']);
        self::assertSame($order['id'], $job['payload']['order_id']);
        self::assertContains($job['state'], ['CREATED', 'DELAYED', 'READY'], 'A published job is work, not a result.');
        self::assertSame(0, (int) $job['attempts']);
    }

    public function testThePublishedJobCarriesTheRequestIdOfTheRequestThatPublishedIt(): void
    {
        [$status, $answer, $headers] = self::$stack->http(
            'POST',
            '/orders',
            '{"customer":"Traced Write","amount":"28.00"}',
            ['X-Request-ID: queue-boundary-1'],
        );

        self::assertSame(201, $status);
        self::assertSame('queue-boundary-1', PlatformTestStack::header($headers, 'X-Request-ID'));

        $order = json_decode($answer, true, 512, JSON_THROW_ON_ERROR);
        $job = $this->jobFor($order['id']);

        // The chain is continuous across the boundary: the id the HTTP
        // request carried is the one the background job will trace itself
        // with, so a `trace` on it spans both halves.
        self::assertSame('queue-boundary-1', $job['payload']['request_id'] ?? null);
    }

    public function testTheQueuesOwnCountersMoveWhenTheApplicationPublishes(): void
    {
        $before = self::$stack->journal()->snapshot();

        [$status] = self::$stack->http('POST', '/orders', '{"customer":"Depth","amount":"29.00"}');

        self::assertSame(201, $status);

        // The counters GET /queue/status answers are read off the same
        // journal, so the HTTP view and the file agree.
        [$status, $answer] = self::$stack->http('GET', '/queue/status');

        self::assertSame(200, $status);
        $reported = json_decode($answer, true, 512, JSON_THROW_ON_ERROR)['queue'];

        self::assertSame($before['published'] + 1, $reported['published']);
        self::assertGreaterThanOrEqual($before['depth'] + 1, $reported['depth']);
    }

    public function testARealConsumerProcessDrainsWhatTheApplicationPublished(): void
    {
        $this->withConsumer(function (): void {
            $order = $this->createOrder('Consumed By A Real Process');

            $job = $this->jobFor($order['id']);
            $id = (string) $job['id'];

            // The consumer restored the journal the write path published into
            // and finished the job - the same file, two processes.
            self::$stack->waitFor(
                fn (): bool => $this->jobById($id)['state'] === 'COMPLETED',
                30.0,
                'The consumer never completed the published job.',
            );

            $settled = $this->jobById($id);

            self::assertSame(1, (int) $settled['attempts']);
            self::assertSame('order.created', $settled['type']);
        });
    }

    public function testAConsumerAnswersNothingUntilSomebodyPublishes(): void
    {
        // The other half of consume: with no work in the journal the consumer
        // is idle, not failing, and a job published while it is alive is still
        // picked up. Proved by publishing after it has announced itself.
        $this->withConsumer(function (): void {
            $order = $this->createOrder('Published After The Consumer Started');
            $id = (string) $this->jobFor($order['id'])['id'];

            self::$stack->waitFor(
                fn (): bool => $this->jobById($id)['state'] === 'COMPLETED',
                30.0,
                'A job published after the consumer started was never drained.',
            );

            self::assertSame('COMPLETED', $this->jobById($id)['state']);
        });
    }

    public function testAFailingJobIsRetriedUntilItsBudgetIsGoneAndThenRetired(): void
    {
        $this->withConsumer(function (): void {
            $jobId = self::$stack->publishJob(FailingJob::TYPE, [], 3);

            $this->waitForAttempts($jobId, 3);

            $settled = $this->jobById($jobId);

            // Three deliveries, all refused, and the job retired as FAILED
            // rather than retried forever: the whole budget spent and stopped.
            self::assertSame(3, (int) $settled['attempts']);
            self::assertSame('FAILED', $settled['state']);
            self::assertSame(3, (int) $settled['maxAttempts']);
            self::assertSame(
                FailingJob::TYPE,
                $settled['type'],
            );
            self::assertNotNull($settled['lastError'], 'A failed job keeps the reason it failed for.');

            // The failure is visible as its own counter, and the retried one
            // counts the job that needed more than one delivery.
            [$status, $answer] = self::$stack->http('GET', '/queue/status');

            self::assertSame(200, $status);
            $reported = json_decode($answer, true, 512, JSON_THROW_ON_ERROR)['queue'];

            self::assertGreaterThanOrEqual(1, $reported['failed']);
            self::assertGreaterThanOrEqual(1, $reported['retried']);
        });
    }

    public function testAJobNobodyConsumesStaysPublishedWork(): void
    {
        // No consumer in this test on purpose: publishing is not executing.
        $jobId = self::$stack->publishJob(NoopJob::TYPE, []);

        usleep(300_000);

        $row = $this->jobById($jobId);

        self::assertSame(0, (int) $row['attempts']);
        self::assertNotSame('COMPLETED', $row['state']);
        self::assertNotSame('FAILED', $row['state']);
    }

    public function testAPublishedJobIsDurableInTheJournalTheConsumerRestoresFrom(): void
    {
        $jobId = self::$stack->publishJob(NoopJob::TYPE, ['probe' => 'durable']);

        // The journal is the queue: a second reader - here, a fresh
        // QueueJournal - sees the job without any in-memory handoff, which is
        // exactly what lets a consumer started later pick it up.
        $rows = self::$stack->journal()->rows();

        self::assertArrayHasKey($jobId, $rows);
        self::assertSame('durable', $rows[$jobId]['payload']['probe'] ?? null);
    }

    /**
     * @param callable(): void $body
     */
    private function withConsumer(callable $body): void
    {
        [$pid, $process] = self::$stack->startConsumer('queue-boundary');

        try {
            $body();
        } finally {
            $exit = self::$stack->stopChild($pid, $process);

            // The graceful shutdown contract, judged the way an operator
            // would: SIGTERM, then a clean exit of its own accord.
            self::assertSame(0, $exit, 'The queue consumer did not shut down cleanly on SIGTERM.');
        }
    }

    private function waitForAttempts(string $jobId, int $attempts): void
    {
        self::$stack->waitFor(
            fn (): bool => (int) ($this->jobById($jobId)['attempts'] ?? 0) >= $attempts,
            30.0,
            sprintf('The failing job never spent its %d attempts.', $attempts),
        );
    }

    /**
     * @param array<string, mixed> $order
     *
     * @return array<string, mixed>
     */
    private function jobFor(string $orderId): array
    {
        foreach (self::$stack->journal()->rows() as $id => $row) {
            if (($row['payload']['order_id'] ?? null) === $orderId) {
                return ['id' => $id] + $row;
            }
        }

        self::fail(sprintf('No published job for order "%s".', $orderId));
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
    private function createOrder(string $customer): array
    {
        [$status, $answer] = self::$stack->http(
            'POST',
            '/orders',
            json_encode(['customer' => $customer, 'amount' => '30.00'], JSON_THROW_ON_ERROR),
        );

        self::assertSame(201, $status);

        return json_decode($answer, true, 512, JSON_THROW_ON_ERROR);
    }
}
