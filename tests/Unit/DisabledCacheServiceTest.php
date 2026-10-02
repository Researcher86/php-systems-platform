<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Tests\Unit;

use PhpMiniCache\Sdk\CacheClientException;
use PhpSystemsPlatform\Cache\CacheService;
use PhpSystemsPlatform\Domain\Order;
use PhpSystemsPlatform\Domain\OrderStatus;
use PHPUnit\Framework\TestCase;

/**
 * A cache that is switched off, which is what PLAN Step 29's Test B measures
 * and the only way to ask "what does this read cost without the cache".
 *
 * The two states a cache can be in are not the same thing, and the whole
 * point of the off switch is that they are not:
 *
 *   unavailable  the server is there or not, the client waits out its
 *                timeout or is refused, and the read is served from the
 *                database anyway - a failure path, with a timeout in it
 *   disabled     there is no cache tier: nothing to connect to, nothing to
 *                wait for, and the cost of the read is the read
 *
 * Measuring a *refused connection* and calling it "without cache" would
 * measure the failure, not the platform, so these tests point the client at a
 * port nothing listens on and then assert the disabled service never notices.
 * The enabled service in the same fixture is the control: it does notice.
 */
final class DisabledCacheServiceTest extends TestCase
{
    /**
     * Port 1: nothing is listening, and a connect attempt is refused at once
     * rather than hanging, so a test that accidentally reached the client
     * would fail quickly instead of stalling.
     *
     * @param array<string, mixed> $overrides
     */
    private function service(array $overrides = []): CacheService
    {
        return CacheService::fromConfig([
            'host' => '127.0.0.1',
            'port' => 1,
            'timeout' => 0.2,
            'enabled' => true,
            ...$overrides,
        ]);
    }

    public function testAMissingFlagLeavesTheCacheOn(): void
    {
        // Every other config key is optional in practice; a platform built
        // from a hand-written config array that predates the switch must not
        // come up with its cache silently disabled - so it reaches the
        // (closed) port and fails, like the enabled control below.
        $service = CacheService::fromConfig(['host' => '127.0.0.1', 'port' => 1, 'timeout' => 0.2]);

        $this->expectException(CacheClientException::class);

        $service->getOrder('any-order-id');
    }

    public function testADisabledLookupIsAMissWithoutTouchingTheCache(): void
    {
        $service = $this->service(['enabled' => false]);

        // Port 1 is closed, so an implementation that reached the client here
        // would raise CacheClientException rather than return null. Returning
        // null quietly is the behaviour under test: no socket was opened.
        self::assertNull($service->getOrder('any-order-id'));
        self::assertSame(1, $service->counters()->misses);
        self::assertSame(0, $service->counters()->hits);
        self::assertSame(0, $service->counters()->bypasses, 'nothing was bypassed: there was nothing to bypass');
    }

    public function testHitsAndMissesStillAddUpToEveryLookupWhenDisabled(): void
    {
        $service = $this->service(['enabled' => false]);

        $service->getOrder('one');
        $service->getOrder('two');

        self::assertSame(2, $service->counters()->misses);
        self::assertSame(2, $service->counters()->misses + $service->counters()->hits);
    }

    public function testADisabledCacheIsNotWrittenToOrCountedAsWritten(): void
    {
        $service = $this->service(['enabled' => false]);
        $order = new Order(
            id: 'order-1',
            customer: 'Someone',
            amount: '10.00',
            product: 'SKU-1',
            status: OrderStatus::CREATED,
            createdAt: '2026-01-01T00:00:00Z',
            updatedAt: '2026-01-01T00:00:00Z',
        );

        $service->setOrder($order);
        $service->deleteOrder('order-1');

        self::assertSame(0, $service->counters()->sets, '/metrics must not describe writes to a cache that does not exist');
        self::assertSame(0, $service->counters()->deletes);
    }

    public function testAnEnabledCacheWithNothingBehindItIsAnErrorTheCallerBypasses(): void
    {
        // The control for the tests above: the same config with the cache on
        // must fail loudly, which is what makes "disabled" a real state and
        // not just a quiet version of the same miss.
        $service = $this->service();

        $this->expectException(CacheClientException::class);

        $service->getOrder('any-order-id');
    }
}
