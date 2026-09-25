<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Tests\Unit\Observability;

use PhpSystemsPlatform\Observability\MetricsRegistry;
use PHPUnit\Framework\TestCase;

/**
 * PLAN Step 23's common/event metrics model: counters only go up, gauges are
 * current-not-cumulative, durations are kept as sum+count and read back as
 * their average, and the metric names are constants so a typo at a call site
 * is a fatal error rather than a counter stuck at zero.
 */
final class MetricsRegistryTest extends TestCase
{
    public function testCountersStartAtZeroAndOnlyGoUp(): void
    {
        $metrics = new MetricsRegistry();

        self::assertSame(0, $metrics->get(MetricsRegistry::HTTP_REQUESTS));

        $metrics->increment(MetricsRegistry::HTTP_REQUESTS);
        $metrics->increment(MetricsRegistry::HTTP_REQUESTS, 3);

        self::assertSame(4, $metrics->get(MetricsRegistry::HTTP_REQUESTS));
    }

    public function testGaugeLatestValueWins(): void
    {
        $metrics = new MetricsRegistry();

        $metrics->gauge(MetricsRegistry::WORKER_RSS, 1024);
        $metrics->gauge(MetricsRegistry::WORKER_RSS, 2048);

        self::assertSame(2048, $metrics->get(MetricsRegistry::WORKER_RSS));
    }

    public function testDurationsReadBackAsTheirAverage(): void
    {
        $metrics = new MetricsRegistry();

        self::assertSame(0, $metrics->get(MetricsRegistry::HTTP_REQUEST_DURATION));

        $metrics->observe(MetricsRegistry::HTTP_REQUEST_DURATION, 0.1);
        $metrics->observe(MetricsRegistry::HTTP_REQUEST_DURATION, 0.3);

        self::assertSame(0.2, $metrics->get(MetricsRegistry::HTTP_REQUEST_DURATION));
    }

    public function testNegativeDurationIsRejected(): void
    {
        $metrics = new MetricsRegistry();

        $this->expectException(\InvalidArgumentException::class);

        $metrics->observe(MetricsRegistry::HTTP_REQUEST_DURATION, -0.5);
    }

    public function testSnapshotMergesEveryKindSorted(): void
    {
        $metrics = new MetricsRegistry();
        $metrics->gauge(MetricsRegistry::WORKER_RSS, 4096);
        $metrics->increment(MetricsRegistry::HTTP_REQUESTS, 2);
        $metrics->observe(MetricsRegistry::HTTP_REQUEST_DURATION, 0.2);
        $metrics->observe(MetricsRegistry::HTTP_REQUEST_DURATION, 0.4);

        self::assertSame(
            [
                MetricsRegistry::HTTP_REQUEST_DURATION => 0.3,
                MetricsRegistry::HTTP_REQUESTS => 2,
                MetricsRegistry::WORKER_RSS => 4096,
            ],
            $metrics->snapshot(),
        );
    }
}
