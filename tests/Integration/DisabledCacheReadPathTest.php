<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Tests\Integration;

use PhpSystemsPlatform\Tests\Support\PlatformTestStack;
use PHPUnit\Framework\TestCase;

/**
 * Test B's premise, over HTTP: a serve started with CACHE_ENABLED=0 is a
 * platform with no cache tier, and answers the read path accordingly.
 *
 * The distinction this suite exists to pin is between a cache that is switched
 * off and a cache that is down, because PLAN Step 29's Test B is the one that
 * has to be the first. "Without cache" measured against a refused connection
 * would be measuring the failure: every read would pay a connect attempt and
 * a timeout, and the report would say the cache was free when the platform had
 * spent the run waiting for it.
 *
 * So three things are checked, in this order, because each is weaker than the
 * one after it:
 *
 *   1. no cache server is listening - the tier is not merely unread, it is
 *      not there
 *   2. the read path is marked `miss` on every read, including the second
 *      read of the same order, where a cache would have had its answer ready
 *   3. the write path still works, because a platform with no cache tier is a
 *      normal platform that happens not to have one
 *
 * This suite owns its serve, because the cache tier is decided when serve
 * starts: a shared stack cannot be reconfigured under the suites holding it.
 */
final class DisabledCacheReadPathTest extends TestCase
{
    private static PlatformTestStack $stack;

    private static string $orderId;

    public static function setUpBeforeClass(): void
    {
        self::$stack = PlatformTestStack::startOwn(['CACHE_ENABLED' => '0']);

        [$status, $answer] = self::$stack->http('POST', '/orders', json_encode([
            'customer' => 'No Cache Tier',
            'amount' => '9.99',
        ], JSON_THROW_ON_ERROR));

        if ($status !== 201) {
            self::fail(sprintf('The write path needs a working platform; POST /orders answered %d.', $status));
        }

        self::$orderId = (string) json_decode($answer, true, 512, JSON_THROW_ON_ERROR)['id'];
    }

    public static function tearDownAfterClass(): void
    {
        $exit = self::$stack->shutdown();

        self::assertSame(0, $exit, 'the serve under test had to shut down cleanly');
    }

    public function testNoCacheServerIsListeningAtAll(): void
    {
        $cachePort = (int) self::$stack->config()['cache']['port'];
        $socket = @fsockopen('127.0.0.1', $cachePort, $code, $message, 0.5);

        self::assertFalse(
            $socket,
            sprintf('A serve with CACHE_ENABLED=0 started a cache server on port %d anyway.', $cachePort),
        );

        if (is_resource($socket)) {
            fclose($socket);
        }
    }

    public function testTheFirstReadIsAMiss(): void
    {
        [$status, , $headers] = self::$stack->http('GET', '/orders/' . self::$orderId);

        self::assertSame(200, $status);
        self::assertSame('miss', PlatformTestStack::header($headers, 'X-Cache'));
    }

    public function testTheSecondReadIsAlsoAMiss(): void
    {
        // This is the load in this test. With a cache, the second read of an
        // order is a hit - the cache boundary suite proves it is - so a `hit`
        // here would mean something is answering reads from a cache tier the
        // platform was told not to run.
        [$status, , $headers] = self::$stack->http('GET', '/orders/' . self::$orderId);

        self::assertSame(200, $status);
        self::assertSame('miss', PlatformTestStack::header($headers, 'X-Cache'));
    }

    public function testTheReadIsTheDatabaseAndNotAFallback(): void
    {
        // A fallback read is also marked `miss`, so the marker alone cannot
        // tell "no cache tier" from "cache tier broken". The body can: this is
        // the order the write path stored, and only the database knows the
        // customer name it was given.
        [, $answer] = self::$stack->http('GET', '/orders/' . self::$orderId);
        $order = json_decode($answer, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('No Cache Tier', $order['customer']);
        self::assertSame('9.99', $order['amount']);
    }

    public function testTheHealthEndpointIsUnaffectedByTheMissingCache(): void
    {
        // Test A measures /health on a platform with the cache on, and Test B
        // on one with it off. If the switch had changed what /health reports,
        // the two phases would not be comparable and the report would be
        // quietly mixing two different platforms.
        [$status, $answer] = self::$stack->http('GET', '/health');

        self::assertSame(200, $status);
        self::assertArrayHasKey('status', json_decode($answer, true, 512, JSON_THROW_ON_ERROR));
    }

    public function testTheMetricsOfAPlatformWithNoCacheDescribeNoCache(): void
    {
        // The reads above went through the lookup and the refill. On a
        // platform with no cache tier every lookup is a miss, and there is
        // nothing to report for writes or bypasses, because nothing was
        // written and nothing failed. So operations == misses, and hits are
        // zero - the numbers describe a tier that does not exist, rather than
        // one that is being politely ignored.
        $metrics = $this->metrics();

        self::assertSame(0.0, $metrics['cache.hit'] ?? 0.0, 'no read was ever answered by a cache');
        self::assertGreaterThan(0.0, $metrics['cache.miss'], 'the reads did go through the lookup');
        self::assertSame(
            $metrics['cache.miss'],
            $metrics['cache.operations'],
            'every cache operation was a lookup: nothing was written to a cache that is not there',
        );
    }

    /**
     * @return array<string, float>
     */
    private function metrics(): array
    {
        [$status, $answer] = self::$stack->http('GET', '/metrics');

        self::assertSame(200, $status);

        $metrics = [];

        foreach (preg_split('/\R/', trim($answer)) ?: [] as $line) {
            $fields = preg_split('/\s+/', trim($line));

            if ($fields !== false && count($fields) === 2) {
                $metrics[$fields[0]] = (float) $fields[1];
            }
        }

        self::assertArrayHasKey('cache.miss', $metrics);
        self::assertArrayHasKey('cache.operations', $metrics);

        return $metrics;
    }
}
