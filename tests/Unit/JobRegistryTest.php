<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Tests\Unit;

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
use PhpSystemsPlatform\Storage\Database;
use PhpSystemsPlatform\Storage\Repositories\OrderRepository;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The type → handler map a queue consumer worker uses to turn a carrier into
 * platform behavior. The registered-type execution path is exercised end to
 * end in ServeIntegrationTest; here the failure half - an unregistered type
 * must surface as a failed attempt, never a silent skip - is the contract
 * under test.
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

    /**
     * A JobContext over services pointed at an unreachable port. Nothing is
     * actually queried here - the registry throws before any handler runs -
     * so the connections only have to exist, not answer.
     */
    private function context(): JobContext
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
            new Producer(
                new InMemoryQueue(new SystemClock()),
                new JobFactory(new SystemClock()),
            )->dispatch('unused'),
            $orders,
            $cache,
        );
    }
}
