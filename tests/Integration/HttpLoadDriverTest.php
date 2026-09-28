<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Tests\Integration;

use PhpSystemsPlatform\Benchmarks\HttpLoadTest;
use PhpSystemsPlatform\Tests\Support\PlatformTestStack;
use PHPUnit\Framework\TestCase;

/**
 * The HTTP load driver, against a running platform.
 *
 * `platform.php load` is a benchmark, and a benchmark is only worth reading
 * if it can be wrong visibly. The numbers it prints are computed by
 * HttpLoadResult (pinned in Unit) and the process costs are read out of /proc
 * (also pinned in Unit); what is left untested is the driver itself - does it
 * send the requests it says it sent, does it keep the connections it reuses
 * straight, and does it report a failure as a failure rather than dropping it
 * on the way to the tally.
 *
 * The request counts are deliberately small. This is about what the driver
 * does with the answers, not about how fast the platform is: a load test
 * proving its own accuracy needs a known number of requests, and a known
 * number of requests has to be small enough to count by hand.
 */
final class HttpLoadDriverTest extends TestCase
{
    private const int REQUESTS = 24;

    private const int CONCURRENCY = 6;

    private static PlatformTestStack $stack;

    public static function setUpBeforeClass(): void
    {
        self::$stack = PlatformTestStack::acquire();
    }

    public static function tearDownAfterClass(): void
    {
        PlatformTestStack::release();
    }

    private function load(): HttpLoadTest
    {
        return new HttpLoadTest(sprintf('http://127.0.0.1:%d', self::$stack->httpPort()), self::CONCURRENCY);
    }

    public function testEveryRequestIsSentAndEveryAnswerIsCounted(): void
    {
        $result = $this->load()->run('A', ['/health'], self::REQUESTS);

        self::assertSame(self::REQUESTS, $result->requests);
        self::assertSame(self::REQUESTS, $result->succeeded);
        self::assertTrue($result->isClean());
        self::assertSame([200 => self::REQUESTS], $result->statusCodes);
        self::assertCount(self::REQUESTS, $result->latencyMs, 'one timing per request, none averaged away');
        self::assertGreaterThan(0.0, $result->wallSeconds);
        self::assertGreaterThan(0.0, $result->requestsPerSecond());
    }

    public function testConcurrencyIsRealRatherThanSequentialInDisguise(): void
    {
        // Six requests in flight against a single-process event loop: the
        // server answers them from one process, so the wall time is bounded
        // below by the slowest request rather than by their sum. What is being
        // checked is that the driver keeps its slots in flight - a driver that
        // quietly went serial would still report REQUESTS successes and look
        // identical in every other column.
        $result = $this->load()->run('A', ['/health'], self::REQUESTS);

        self::assertSame(self::REQUESTS, $result->succeeded);
        self::assertLessThan(
            $result->averageLatencyMs() * self::REQUESTS / 1000,
            $result->wallSeconds,
            'a serial driver would take about the sum of the request times',
        );
    }

    public function testTheReadPathMarkerIsReadRatherThanAssumed(): void
    {
        $paths = [];

        for ($i = 0; $i < 8; $i++) {
            [$status, $answer] = self::$stack->http('POST', '/orders', json_encode([
                'customer' => 'Load Driver',
                'amount' => '12.00',
            ], JSON_THROW_ON_ERROR));

            self::assertSame(201, $status);
            $paths[] = '/orders/' . json_decode($answer, true, 512, JSON_THROW_ON_ERROR)['id'];
        }

        $result = $this->load()->run('C2', $paths, count($paths));

        self::assertTrue($result->isClean());
        self::assertSame(['hit' => count($paths)], $result->cacheTally, 'created orders are already cached');

        // ...which is exactly why the runner seeds its corpus with direct
        // inserts: a phase that wanted misses and used these orders would
        // report this row as hits.
    }

    public function testAFailedRequestIsCountedAsAFailedRequest(): void
    {
        $result = $this->load()->run('404', ['/orders/no-such-order-' . uniqid('', false)], 6);

        self::assertSame(6, $result->requests);
        self::assertSame(0, $result->succeeded);
        self::assertFalse($result->isClean());
        self::assertSame([404 => 6], $result->statusCodes);
        self::assertSame(['miss' => 6], $result->cacheTally, 'a miss is what a 404 on the read path is marked as');
    }

    public function testAConnectionFailureIsVisibleRatherThanDropped(): void
    {
        // Nothing is listening on port 1, so every request fails to connect.
        // curl reports that as status 0; the driver has to keep it, because a
        // load test that dropped its failures would report the throughput of
        // the requests that happened to survive.
        $load = new HttpLoadTest('http://127.0.0.1:1', 4, 1.0);
        $result = $load->run('dead', ['/health'], 8);

        self::assertSame(8, $result->requests);
        self::assertSame(0, $result->succeeded);
        self::assertFalse($result->isClean());
        self::assertSame([0 => 8], $result->statusCodes);
    }

    public function testTheSamePathsAreReusedAcrossPhasesOnOneDriver(): void
    {
        // Two phases in a row, as the runner runs them: C1's misses are C2's
        // reason to exist, and the second phase has to reach the same platform
        // through handles the first phase reset.
        $load = $this->load();
        $first = $load->run('A', ['/health'], 4);
        $second = $load->run('A', ['/health'], 4);

        self::assertTrue($first->isClean());
        self::assertTrue($second->isClean());
        self::assertSame([200 => 4], $second->statusCodes);
    }
}
