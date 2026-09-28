<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Tests\Unit;

use PhpSystemsPlatform\Benchmarks\LoadTestReport;
use PHPUnit\Framework\TestCase;

/**
 * The report PLAN Step 29 ends with: one table a person reads, and one array
 * Step 31 quotes into docs/benchmarks.md.
 *
 * Both are rendered from the same array, so the risk here is not that they
 * disagree - it is that a row is quietly missing, or that a column says
 * something the numbers behind it do not support. The "vs 1" column is the
 * one to watch: it is a ratio, and a ratio whose denominator was assumed to
 * be the first row rather than the one-worker row would quietly compare the
 * wrong two runs.
 */
final class LoadTestReportTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function phase(string $test, string $name, int $requests, float $rps, array $markers): array
    {
        return [
            'test' => $test,
            'name' => $name,
            'label' => $test,
            'requests' => $requests,
            'succeeded' => $requests,
            'failed' => 0,
            'wall_seconds' => round($requests / $rps, 3),
            'requests_per_second' => $rps,
            'latency_ms' => ['avg' => 1.5, 'p50' => 1.2, 'p95' => 2.4, 'p99' => 3.1, 'max' => 4.0],
            'status_codes' => [200 => $requests],
            'x_cache' => $markers,
            'serve_cpu_seconds' => 0.5,
            'serve_peak_rss_mb' => 28.1,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function queue(int $workers, float $throughput, float $utilization): array
    {
        return [
            'jobs' => 1000,
            'workers' => $workers,
            'wall_seconds' => round(1000 / $throughput, 3),
            'throughput_per_sec' => $throughput,
            'avg_latency_ms' => 60.0,
            'p95_latency_ms' => 62.0,
            'queue_depth' => 1000,
            'worker_utilization' => $utilization,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function reportRows(): array
    {
        return [
            $this->phase('A', 'GET /health (server only)', 1000, 9500.0, ['(none)' => 1000]),
            $this->phase('C1', 'GET /orders/{id} (first read of each order)', 1000, 2100.0, ['miss' => 1000]),
            $this->phase('C2', 'GET /orders/{id} (same orders, now cached)', 1000, 11000.0, ['hit' => 1000]),
            $this->phase('B', 'GET /orders/{id} (CACHE_ENABLED=0)', 1000, 2050.0, ['miss' => 1000]),
        ];
    }

    private function report(): LoadTestReport
    {
        return new LoadTestReport(
            ['base_url' => 'http://127.0.0.1:8080', 'concurrency' => 8, 'queue_jobs' => 1000],
            $this->reportRows(),
            $this->queue(4, 800.0, 0.4),
            [
                $this->queue(1, 600.0, 0.25),
                $this->queue(2, 700.0, 0.3),
                $this->queue(4, 800.0, 0.4),
                $this->queue(8, 900.0, 0.5),
            ],
        );
    }

    public function testTheArrayShapeIsTheOneTheDocsQuote(): void
    {
        $array = $this->report()->toArray();

        self::assertSame(['environment', 'http', 'queue', 'scaling'], array_keys($array));
        self::assertCount(4, $array['http']);
        self::assertSame(4, $array['queue']['workers']);
        self::assertCount(4, $array['scaling']);
        self::assertSame([1, 2, 4, 8], array_column($array['scaling'], 'workers'));
    }

    public function testTheJsonIsDecodableAndCarriesTheSameNumbers(): void
    {
        $json = $this->report()->toJson();

        self::assertJson($json);

        // assertEquals, not assertSame: JSON has one number type, so a float
        // that happens to be whole (9500.0) comes back as an int. The values
        // are what has to survive the round trip, not their PHP types.
        self::assertEquals($this->report()->toArray(), json_decode($json, true, 512, JSON_THROW_ON_ERROR));
    }

    public function testTheTableHasOneBlockPerPlannedTest(): void
    {
        $text = $this->report()->toText();

        self::assertStringContainsString('Environment', $text);
        self::assertStringContainsString('Test A - HTTP only, and the read path (Tests B and C)', $text);
        self::assertStringContainsString('Test D - background jobs through the queue', $text);
        self::assertStringContainsString('Test E - worker scaling', $text);
    }

    public function testEveryPhaseIsNamedInTheTable(): void
    {
        $text = $this->report()->toText();

        foreach (['A', 'C1', 'C2', 'B'] as $test) {
            self::assertStringContainsString(sprintf("\n  %-4s ", $test), $text, sprintf('phase %s is missing', $test));
        }
    }

    public function testTheCacheMarkersAreCountedNotAsserted(): void
    {
        $text = $this->report()->toText();

        self::assertStringContainsString('read path, by the platform\'s own X-Cache marker', $text);
        self::assertStringContainsString('hit=1000', $text, 'the hit phase has to show hits in the report');
        self::assertStringContainsString('miss=1000', $text);
        self::assertStringContainsString('(none)=1000', $text, 'the health phase carries no marker, and says so');
    }

    public function testTheScalingRatiosAreAgainstOneWorker(): void
    {
        $text = $this->report()->toText();

        self::assertStringContainsString('vs 1', $text);
        // 700/600 = 1.17, 900/600 = 1.50. A denominator taken from the first
        // row would divide everything by 600 too - so the assertion that
        // matters is the one below.
        self::assertStringContainsString('1.17', $text);
        self::assertStringContainsString('1.50', $text);
        self::assertStringNotContainsString('no one-worker run', $text);
    }

    public function testARunWithoutAOneWorkerRowSaysSoInsteadOfPretending(): void
    {
        $report = new LoadTestReport(
            ['base_url' => 'http://127.0.0.1:8080'],
            $this->reportRows(),
            $this->queue(4, 800.0, 0.4),
            [$this->queue(4, 800.0, 0.4), $this->queue(8, 900.0, 0.5)],
        );

        $text = $report->toText();

        self::assertStringContainsString('no one-worker run: ratios are against 4 workers', $text);
        self::assertStringContainsString('1.12', $text, '900/800, against the smallest pool actually measured');
    }

    public function testTheQueueBlockNamesItsWorkload(): void
    {
        $text = $this->report()->toText();

        self::assertStringContainsString('1000 jobs, 4 workers', $text);
        self::assertStringContainsString('800.0 jobs/s', $text);
        self::assertStringContainsString('utilization 40.0%', $text);
    }

    public function testTheEnvironmentIsPartOfTheReportNotAFootnote(): void
    {
        // A throughput number without the machine it came from is a rumour.
        $text = $this->report()->toText();

        self::assertStringContainsString('http://127.0.0.1:8080', $text);
        self::assertStringContainsString('concurrency', $text);
    }
}
