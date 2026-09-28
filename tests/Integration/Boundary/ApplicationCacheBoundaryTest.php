<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Tests\Integration\Boundary;

use PhpSystemsPlatform\Domain\Order;
use PhpSystemsPlatform\Domain\OrderStatus;
use PhpSystemsPlatform\Tests\Support\PlatformTestStack;
use PHPUnit\Framework\TestCase;

/**
 * The Application → Cache boundary, against the running stack.
 *
 * PLAN Step 27 names hit, miss and invalidation. The read and write paths
 * under test are the real ones - the handlers' - with the cache server serve
 * started behind them, and the entry under test manipulated over the same
 * socket the handlers use, so a hit, a miss and an invalidation are observed
 * exactly where a client would see them: in the X-Cache header of the
 * answer, checked against the cache server's own state.
 */
final class ApplicationCacheBoundaryTest extends TestCase
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

    public function testAWritePopulatesTheEntrySoTheVeryFirstReadIsAHit(): void
    {
        $order = $this->createOrder('Cache Hit', '18.00');

        // The write path's populate-on-write, seen from outside: no miss first.
        [$status, , $headers] = self::$stack->http('GET', '/orders/' . $order['id']);

        self::assertSame(200, $status);
        self::assertSame('hit', PlatformTestStack::header($headers, 'X-Cache'));

        // And the entry it hit really is the server's, not a leftover of this
        // test process: the harness's own client reads the same key back.
        $cached = self::$stack->cache()->getOrder($order['id']);

        self::assertNotNull($cached);
        self::assertSame('Cache Hit', $cached['customer']);
    }

    public function testAMissIsServedFromTheDatabaseAndRefilledOnTheWayOut(): void
    {
        $order = $this->createOrder('Cache Miss', '19.00');

        // Empty the entry the way an eviction (or a cold cache) would.
        self::$stack->cache()->deleteOrder($order['id']);
        self::assertNull(self::$stack->cache()->getOrder($order['id']));

        [$status, $answer, $headers] = self::$stack->http('GET', '/orders/' . $order['id']);

        self::assertSame(200, $status);
        self::assertSame('miss', PlatformTestStack::header($headers, 'X-Cache'));
        self::assertSame($order['id'], json_decode($answer, true, 512, JSON_THROW_ON_ERROR)['id']);

        // The miss refilled it, so the next read is a hit - and the refilled
        // entry agrees with the row the database has right now, which is the
        // whole contract of a miss on this path.
        [, , $headers] = self::$stack->http('GET', '/orders/' . $order['id']);
        self::assertSame('hit', PlatformTestStack::header($headers, 'X-Cache'));

        $cached = self::$stack->cache()->getOrder($order['id']);
        self::assertNotNull($cached);
        self::assertSame(
            self::$stack->database()->read('SELECT amount FROM orders WHERE id = ?', [$order['id']])[0]['amount'],
            $cached['amount'],
        );
    }

    public function testAnOrderTheDatabaseDoesNotKnowIsAMissAndA404(): void
    {
        [, , $headers] = self::$stack->http('GET', '/orders/00000000-0000-4000-8000-000000000000');

        self::assertSame('miss', PlatformTestStack::header($headers, 'X-Cache'));
    }

    public function testAnUpdateInvalidatesTheEntrySoTheNextReadSeesTheNewRow(): void
    {
        $order = $this->createOrder('Cache Invalidation', '21.00');

        [, , $headers] = self::$stack->http('GET', '/orders/' . $order['id']);
        self::assertSame('hit', PlatformTestStack::header($headers, 'X-Cache'));

        [$status, $answer] = self::$stack->http('PUT', '/orders/' . $order['id'], '{"status":"completed"}');

        self::assertSame(200, $status);

        // The write deleted the copy, so a hit here would be a lie.
        self::assertNull(self::$stack->cache()->getOrder($order['id']));

        [$status, $answer, $headers] = self::$stack->http('GET', '/orders/' . $order['id']);

        self::assertSame(200, $status);
        self::assertSame('miss', PlatformTestStack::header($headers, 'X-Cache'));
        self::assertSame('completed', json_decode($answer, true, 512, JSON_THROW_ON_ERROR)['status']);

        // ... and the read that refilled it is a hit from then on.
        [, , $headers] = self::$stack->http('GET', '/orders/' . $order['id']);
        self::assertSame('hit', PlatformTestStack::header($headers, 'X-Cache'));
    }

    public function testTheCacheStaysDerivedStateTheDatabaseRemainsAuthoritative(): void
    {
        $order = $this->createOrder('Cache Derived', '23.00');

        // A write that does not go through the handlers at all - the case
        // where the cached copy is behind by definition.
        self::$stack->database()->write(
            'UPDATE orders SET customer = ? WHERE id = ?',
            ['Renamed Outside The Api', $order['id']],
        );

        // The cache still answers with what it has, which is the whole reason
        // a hit is a hit.
        [, , $headers] = self::$stack->http('GET', '/orders/' . $order['id']);
        self::assertSame('hit', PlatformTestStack::header($headers, 'X-Cache'));

        // Once the entry is gone the database is what the client gets - so a
        // miss is never a stale answer, only a slower one.
        self::$stack->cache()->deleteOrder($order['id']);

        [, $answer, $headers] = self::$stack->http('GET', '/orders/' . $order['id']);

        self::assertSame('miss', PlatformTestStack::header($headers, 'X-Cache'));
        self::assertSame('Renamed Outside The Api', json_decode($answer, true, 512, JSON_THROW_ON_ERROR)['customer']);
    }

    public function testTheCacheServiceCountsHitsMissesSetsAndDeletesAtThisSeam(): void
    {
        // The handlers' own service, used directly, so the counters the
        // read path maintains are visible without going through HTTP.
        $cache = self::$stack->newCache();
        $order = new Order(
            id: 'boundary-cache-' . uniqid('', false),
            customer: 'Counted',
            amount: '5.00',
            product: 'standard',
            status: OrderStatus::CREATED,
            createdAt: '2026-01-01T00:00:00Z',
            updatedAt: '2026-01-01T00:00:00Z',
        );

        try {
            self::assertNull($cache->getOrder($order->id));

            $cache->setOrder($order);

            self::assertSame(1, $cache->counters()->misses);
            self::assertSame(1, $cache->counters()->sets);

            $cached = $cache->getOrder($order->id);

            self::assertNotNull($cached);
            self::assertSame('Counted', $cached['customer']);
            self::assertSame(1, $cache->counters()->hits);

            $cache->deleteOrder($order->id);

            self::assertSame(1, $cache->counters()->deletes);
            self::assertNull($cache->getOrder($order->id));
        } finally {
            $cache->deleteOrder($order->id);
            $cache->close();
        }
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
