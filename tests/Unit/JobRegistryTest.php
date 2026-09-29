<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Tests\Unit;

use PhpJobQueue\Job\Job as QueueJob;
use PhpJobQueue\Producer\JobFactory;
use PhpJobQueue\Producer\Producer;
use PhpJobQueue\Queue\InMemoryQueue;
use PhpJobQueue\Support\SystemClock;
use PhpMiniCache\Sdk\CacheClient;
use PhpMiniDatabase\Client\ClientConfig;
use PhpSystemsPlatform\Cache\CacheCounters;
use PhpSystemsPlatform\Cache\CacheService;
use PhpSystemsPlatform\Domain\OrderService;
use PhpSystemsPlatform\Queue\JobContext;
use PhpSystemsPlatform\Queue\JobRegistry;
use PhpSystemsPlatform\Queue\Jobs\DemoSlowJob;
use PhpSystemsPlatform\Queue\Jobs\FailingJob;
use PhpSystemsPlatform\Queue\Jobs\OrderCreatedJob;
use PhpSystemsPlatform\Storage\Database;
use PhpSystemsPlatform\Storage\Repositories\OrderRepository;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The type → handler map a queue consumer worker uses to turn a carrier into
 * platform behavior. The registered-type execution path is exercised end to
 * end in ServeIntegrationTest; here the failure half - an unregistered type
 * must surface as a failed attempt, never a silent skip - is the contract
 * under test, alongside validate()/shouldRetry(): a payload that can never
 * succeed is failed without spending the job's remaining attempts.
 */
final class JobRegistryTest extends TestCase
{
    public function testExecuteThrowsForAnUnregisteredType(): void
    {
        $carrier = new Producer(
            new InMemoryQueue(new SystemClock()),
            new JobFactory(new SystemClock()),
        )->dispatch('unknown.type');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No platform job is registered for type "unknown.type".');

        new JobRegistry()->execute($carrier, $this->context());
    }

    public function testValidateHasNoOpinionOnAnUnregisteredType(): void
    {
        self::assertNull(JobRegistry::validate('unknown.type', []));
    }

    public function testValidateHasNoOpinionOnATypeThatDoesNotOptIn(): void
    {
        self::assertNull(JobRegistry::validate('bench.noop', ['anything' => 'goes']));
    }

    public function testValidateRejectsAPayloadItsTypeRejects(): void
    {
        self::assertNotNull(JobRegistry::validate(OrderCreatedJob::TYPE, []));
    }

    public function testValidatePassesAPayloadItsTypeAccepts(): void
    {
        self::assertNull(JobRegistry::validate(OrderCreatedJob::TYPE, ['order_id' => 'abc']));
    }

    public function testShouldRetryRefusesAJobWithAnInvalidPayload(): void
    {
        $job = new Producer(
            new InMemoryQueue(new SystemClock()),
            new JobFactory(new SystemClock()),
        )->dispatch(OrderCreatedJob::TYPE, []);

        $eligible = (JobRegistry::shouldRetry())($job, new RuntimeException('order.created payload is missing order_id.'));

        self::assertFalse($eligible);
    }

    public function testShouldRetryAllowsAJobWithAValidPayload(): void
    {
        $job = new Producer(
            new InMemoryQueue(new SystemClock()),
            new JobFactory(new SystemClock()),
        )->dispatch(OrderCreatedJob::TYPE, ['order_id' => 'abc']);

        $eligible = (JobRegistry::shouldRetry())($job, new RuntimeException('order not found'));

        self::assertTrue($eligible);
    }

    public function testFailingJobIsRegisteredAndFailsOnEveryExecution(): void
    {
        // PLAN Step 22: the deliberate failure is a registered job like any
        // other, so the whole retry machinery treats it as a real failure -
        // a well-formed but hopeless one that may spend its attempts budget.
        $carrier = new Producer(
            new InMemoryQueue(new SystemClock()),
            new JobFactory(new SystemClock()),
        )->dispatch(FailingJob::TYPE);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Injected failure (demo.failing).');

        new JobRegistry()->execute($carrier, $this->context());
    }

    public function testShouldRetryLetsTheFailingJobSpendItsBudget(): void
    {
        // The reason demo.failing reaches max_attempts instead of dying on
        // attempt one: it has no ValidatesPayload, so validate() has no
        // opinion and shouldRetry() keeps it eligible while attempts remain.
        $job = new Producer(
            new InMemoryQueue(new SystemClock()),
            new JobFactory(new SystemClock()),
        )->dispatch(FailingJob::TYPE);

        $eligible = (JobRegistry::shouldRetry())($job, new RuntimeException('Injected failure (demo.failing).'));

        self::assertTrue($eligible);
    }

    public function testSlowJobIsRegisteredAndHoldsTheWorkerForTheSecondsItWasGiven(): void
    {
        // PLAN Step 28: the duration lever, on the same terms as
        // demo.failing - a registered type, executed by the registry, so the
        // queue treats a slow job as an ordinary one. A quarter of a second
        // is the shortest honest measurement: the assertion is that time
        // passed, not that a timer is exact.
        $carrier = $this->carrier(DemoSlowJob::TYPE, ['seconds' => 0.25]);

        $started = microtime(true);
        new JobRegistry()->execute($carrier, $this->context($carrier));
        $elapsed = microtime(true) - $started;

        self::assertGreaterThanOrEqual(0.25, $elapsed);
        self::assertLessThan(5.0, $elapsed);
    }

    public function testValidateRejectsASlowJobWithoutSeconds(): void
    {
        self::assertNotNull(JobRegistry::validate(DemoSlowJob::TYPE, []));
    }

    public function testValidateRejectsASlowJobAskingForNoTimeAtAll(): void
    {
        self::assertNotNull(JobRegistry::validate(DemoSlowJob::TYPE, ['seconds' => 0]));
    }

    public function testValidateRejectsASlowJobAskingToOutliveThePool(): void
    {
        // Longer than the pool's execution timeout is not a slow job, it is a
        // request to be killed - a different failure, and one this type
        // refuses to stage by accident.
        self::assertNotNull(JobRegistry::validate(DemoSlowJob::TYPE, ['seconds' => DemoSlowJob::MAX_SECONDS + 1]));
    }

    public function testValidateAcceptsASlowJobInsideItsWindow(): void
    {
        self::assertNull(JobRegistry::validate(DemoSlowJob::TYPE, ['seconds' => 2.5]));
        self::assertNull(JobRegistry::validate(DemoSlowJob::TYPE, ['seconds' => DemoSlowJob::MAX_SECONDS]));
    }

    public function testShouldRetryRefusesASlowJobWhoseSecondsCouldNeverBeRead(): void
    {
        $job = $this->carrier(DemoSlowJob::TYPE, ['seconds' => 'soon']);

        $eligible = (JobRegistry::shouldRetry())($job, new RuntimeException('demo.slow payload is missing a numeric "seconds".'));

        self::assertFalse($eligible);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function carrier(string $type, array $payload = []): QueueJob
    {
        return new Producer(
            new InMemoryQueue(new SystemClock()),
            new JobFactory(new SystemClock()),
        )->dispatch($type, $payload);
    }

    /**
     * A JobContext over services pointed at an unreachable port. Nothing is
     * actually queried here - the registry throws before any handler runs, and
     * demo.slow touches no service at all - so the connections only have to
     * exist, not answer.
     */
    private function context(?QueueJob $carrier = null): JobContext
    {
        $database = Database::fromConfig(new ClientConfig(
            host: '127.0.0.1',
            port: 1,
            connectTimeoutSeconds: 0.01,
        ));
        $orders = new OrderService(new OrderRepository($database), null);
        $cache = new CacheService(
            new CacheClient(host: '127.0.0.1', port: 1, timeoutSeconds: 0.01),
            new CacheCounters(),
        );

        return new JobContext(
            $carrier ?? $this->carrier('unused'),
            $orders,
            $cache,
        );
    }
}
