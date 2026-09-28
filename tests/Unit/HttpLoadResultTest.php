<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Tests\Unit;

use PhpSystemsPlatform\Benchmarks\HttpLoadResult;
use PHPUnit\Framework\TestCase;

/**
 * The arithmetic behind every number PLAN Step 29's read path reports.
 *
 * These are the parts of a load test that can be wrong quietly: a percentile
 * computed off the wrong rank, or a phase that counts 500s as successes, both
 * produce a table that looks fine and says nothing. The aggregation is pure,
 * so it is pinned here rather than discovered during a run.
 */
final class HttpLoadResultTest extends TestCase
{
    public function testRateIsRequestsOverWallTime(): void
    {
        $result = HttpLoadResult::fromSamples('C1', 1000, array_fill(0, 1000, 2.0), 2.0, [200 => 1000], []);

        self::assertSame(500.0, $result->requestsPerSecond());
    }

    public function testRateIsZeroWhenNothingWasSent(): void
    {
        $result = HttpLoadResult::fromSamples('A', 0, [], 0.0, [], []);

        self::assertSame(0.0, $result->requestsPerSecond());
    }

    public function testOnlyTwoAndThreeHundredCountAsSucceeded(): void
    {
        $result = HttpLoadResult::fromSamples(
            'C1',
            10,
            array_fill(0, 10, 1.0),
            1.0,
            [200 => 6, 201 => 1, 304 => 1, 404 => 1, 500 => 1],
            [],
        );

        self::assertSame(8, $result->succeeded);
        self::assertFalse($result->isClean());
    }

    public function testAConnectionFailureCountsAsAFailedRequest(): void
    {
        // curl reports status 0 when it never got a response: a refused
        // connection, a timeout. It is not a success, and it is not an HTTP
        // status either - the tally has to keep it visible.
        $result = HttpLoadResult::fromSamples('B', 4, [1.0, 1.0, 1.0, 1.0], 1.0, [200 => 2, 0 => 2], []);

        self::assertSame(2, $result->succeeded);
        self::assertArrayHasKey(0, $result->statusCodes);
        self::assertFalse($result->isClean());
    }

    public function testSamplesAreSortedSoPercentilesHaveAnOrderToRead(): void
    {
        $result = HttpLoadResult::fromSamples('C1', 3, [30.0, 10.0, 20.0], 1.0, [200 => 3], []);

        self::assertSame([10.0, 20.0, 30.0], $result->latencyMs);
        self::assertSame(20.0, $result->averageLatencyMs());
    }

    public function testPercentilesAreNearestRankNotInterpolated(): void
    {
        // Nearest rank over 1..100: p50 is the 50th sample, p95 the 95th, p99
        // the 99th. An interpolated percentile would report 49.5 here, a time
        // no request took.
        $samples = [];

        for ($ms = 1; $ms <= 100; $ms++) {
            $samples[] = (float) $ms;
        }

        $result = HttpLoadResult::fromSamples('A', 100, $samples, 1.0, [200 => 100], []);

        self::assertSame(50.0, $result->percentileLatencyMs(50.0));
        self::assertSame(95.0, $result->percentileLatencyMs(95.0));
        self::assertSame(99.0, $result->percentileLatencyMs(99.0));
        self::assertSame(100.0, $result->maxLatencyMs());
    }

    public function testTheLastPercentileOfOneSampleIsThatSample(): void
    {
        $result = HttpLoadResult::fromSamples('A', 1, [7.5], 0.1, [200 => 1], ['(none)' => 1]);

        self::assertSame(7.5, $result->percentileLatencyMs(99.0));
        self::assertSame(7.5, $result->maxLatencyMs());
        self::assertSame(7.5, $result->averageLatencyMs());
    }

    public function testEmptySamplesAreZeroRatherThanUndefined(): void
    {
        $result = HttpLoadResult::fromSamples('A', 0, [], 0.0, [], []);

        self::assertSame(0.0, $result->averageLatencyMs());
        self::assertSame(0.0, $result->percentileLatencyMs(95.0));
        self::assertSame(0.0, $result->maxLatencyMs());
    }

    public function testTheArrayFormCarriesTheEvidenceNotJustTheSummary(): void
    {
        $result = HttpLoadResult::fromSamples('C2', 4, [1.0, 1.0, 2.0, 2.0], 0.4, [200 => 4], ['hit' => 4]);
        $array = $result->toArray();

        self::assertSame('C2', $array['label']);
        self::assertSame(4, $array['requests']);
        self::assertSame(0, $array['failed']);
        self::assertSame(10.0, $array['requests_per_second']);
        self::assertSame([200 => 4], $array['status_codes']);
        self::assertSame(['hit' => 4], $array['x_cache']);
        self::assertSame(1.5, $array['latency_ms']['avg']);
        self::assertSame(2.0, $array['latency_ms']['max']);
    }
}
