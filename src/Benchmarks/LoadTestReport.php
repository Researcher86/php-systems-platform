<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Benchmarks;

/**
 * What a load run measured (PLAN Step 29): a payload plus two renderings.
 * toArray()/toJson() is the machine shape docs/benchmarks.md quotes; toText()
 * is the human table, built from the same data so the two cannot disagree.
 * The environment is part of the result because a throughput number without
 * the machine it came from means little.
 */
final readonly class LoadTestReport
{
    /**
     * @param array<string, mixed>        $environment what the run ran on
     * @param list<array<string, mixed>>  $http        Tests A, B and C, one
     *                                               row per phase
     * @param array<string, mixed>        $queue       Test D
     * @param list<array<string, mixed>>  $scaling     Test E
     */
    public function __construct(
        public array $environment,
        public array $http,
        public array $queue,
        public array $scaling,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'environment' => $this->environment,
            'http' => $this->http,
            'queue' => $this->queue,
            'scaling' => $this->scaling,
        ];
    }

    public function toJson(): string
    {
        return (string) json_encode(
            $this->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * The human table: one block per PLAN test, with the environment the
     * numbers were taken on above them.
     */
    public function toText(): string
    {
        $out = "Load test (PLAN Step 29)\n\n";
        $out .= "Environment\n";

        foreach ($this->environment as $key => $value) {
            $out .= sprintf("  %-22s %s\n", $key, is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value);
        }

        $out .= "\nTest A - HTTP only, and the read path (Tests B and C)\n";
        $out .= sprintf(
            "  %-4s %-42s %7s %9s %9s %9s %9s %8s %7s\n",
            'test',
            'workload',
            'requests',
            'rps',
            'avg ms',
            'p95 ms',
            'p99 ms',
            'cpu s',
            'peak MB',
        );

        foreach ($this->http as $row) {
            $out .= sprintf(
                "  %-4s %-42s %7d %9.1f %9.2f %9.2f %9.2f %8.2f %7.1f\n",
                $row['test'],
                $row['name'],
                $row['requests'],
                $row['requests_per_second'],
                $row['latency_ms']['avg'],
                $row['latency_ms']['p95'],
                $row['latency_ms']['p99'],
                $row['serve_cpu_seconds'],
                $row['serve_peak_rss_mb'],
            );
        }

        $out .= $this->httpNotes();
        $out .= "\nTest D - background jobs through the queue\n";
        $out .= sprintf(
            "  %d jobs, %d workers: %.1f jobs/s, avg %.2f ms, p95 %.2f ms, peak depth %d, utilization %.1f%%\n",
            $this->queue['jobs'],
            $this->queue['workers'],
            $this->queue['throughput_per_sec'],
            $this->queue['avg_latency_ms'],
            $this->queue['p95_latency_ms'],
            $this->queue['queue_depth'],
            $this->queue['worker_utilization'] * 100,
        );

        $out .= "\nTest E - worker scaling (same workload, pool resized)\n";
        $out .= sprintf("  %-8s %9s %9s %9s %9s %12s\n", 'workers', 'jobs/s', 'avg ms', 'p95 ms', 'vs 1', 'utilization');

        // "vs 1" is against the one-worker row, looked up by worker count
        // rather than position. Without one, the smallest pool is the
        // baseline and a note says so.
        $baselineRow = null;

        foreach ($this->scaling as $row) {
            if ((int) $row['workers'] === 1) {
                $baselineRow = $row;

                break;
            }

            if ($baselineRow === null || (int) $row['workers'] < (int) $baselineRow['workers']) {
                $baselineRow = $row;
            }
        }

        $baseline = (float) ($baselineRow['throughput_per_sec'] ?? 0.0);
        $out .= $baselineRow === null || (int) $baselineRow['workers'] === 1
            ? ''
            : sprintf("  (no one-worker run: ratios are against %d workers)\n", (int) $baselineRow['workers']);

        foreach ($this->scaling as $row) {
            $out .= sprintf(
                "  %-8d %9.1f %9.2f %9.2f %9.2f %11.1f%%\n",
                $row['workers'],
                $row['throughput_per_sec'],
                $row['avg_latency_ms'],
                $row['p95_latency_ms'],
                $baseline > 0.0 ? $row['throughput_per_sec'] / $baseline : 0.0,
                $row['worker_utilization'] * 100,
            );
        }

        return $out;
    }

    /** Each phase's X-Cache markers, counted: evidence of what the cache did. */
    private function httpNotes(): string
    {
        $out = "\n  read path, by the platform's own X-Cache marker:\n";

        foreach ($this->http as $row) {
            $tally = [];

            foreach ($row['x_cache'] as $marker => $count) {
                $tally[] = sprintf('%s=%d', $marker, $count);
            }

            $out .= sprintf("    %-4s %s\n", $row['test'], implode(' ', $tally));
        }

        return $out;
    }
}
