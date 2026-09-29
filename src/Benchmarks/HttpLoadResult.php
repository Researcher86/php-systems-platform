<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Benchmarks;

/**
 * What one load phase measured (PLAN Step 29's Tests A, B and C).
 *
 * Every per-request time is kept, not just a mean, so the tail is visible.
 * Status codes and X-Cache markers are tallied rather than assumed, so a
 * phase that answered 500s at a great RPS cannot pass for a good one.
 */
final readonly class HttpLoadResult
{
    public const float MILLISECONDS = 1000.0;

    /**
     * @param list<float>        $latencyMs   every per-request time, ascending
     * @param array<int, int>    $statusCodes answers per status code
     * @param array<string, int> $cacheTally  answers per X-Cache value
     */
    public function __construct(
        public string $label,
        public int $requests,
        public int $succeeded,
        public float $wallSeconds,
        public array $latencyMs = [],
        public array $statusCodes = [],
        public array $cacheTally = [],
    ) {
    }

    /**
     * Build a result from raw per-request samples, in the order they finished.
     *
     * @param list<float>       $latenciesMs
     * @param array<int, int>   $statusCodes
     * @param array<string, int> $cacheTally
     */
    public static function fromSamples(
        string $label,
        int $requests,
        array $latenciesMs,
        float $wallSeconds,
        array $statusCodes,
        array $cacheTally,
    ): self {
        sort($latenciesMs);

        $succeeded = 0;

        foreach ($statusCodes as $status => $count) {
            if ($status >= 200 && $status < 400) {
                $succeeded += $count;
            }
        }

        return new self(
            $label,
            $requests,
            $succeeded,
            $wallSeconds,
            $latenciesMs,
            $statusCodes,
            $cacheTally,
        );
    }

    /** Requests per second over the phase's wall time. */
    public function requestsPerSecond(): float
    {
        return $this->wallSeconds > 0.0 ? round($this->requests / $this->wallSeconds, 1) : 0.0;
    }

    public function averageLatencyMs(): float
    {
        return $this->latencyMs === [] ? 0.0 : round(array_sum($this->latencyMs) / count($this->latencyMs), 2);
    }

    /**
     * The $percentile-th percentile of the samples, nearest-rank.
     *
     * Nearest-rank rather than interpolated, so the answer is always a time
     * some request actually took.
     */
    public function percentileLatencyMs(float $percentile): float
    {
        if ($this->latencyMs === []) {
            return 0.0;
        }

        $rank = (int) ceil($percentile / 100 * count($this->latencyMs));

        return round($this->latencyMs[max(0, min($rank, count($this->latencyMs)) - 1)], 2);
    }

    public function maxLatencyMs(): float
    {
        return $this->latencyMs === [] ? 0.0 : round($this->latencyMs[count($this->latencyMs) - 1], 2);
    }

    public function isClean(): bool
    {
        return $this->succeeded === $this->requests;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'requests' => $this->requests,
            'succeeded' => $this->succeeded,
            'failed' => $this->requests - $this->succeeded,
            'wall_seconds' => round($this->wallSeconds, 3),
            'requests_per_second' => $this->requestsPerSecond(),
            'latency_ms' => [
                'avg' => $this->averageLatencyMs(),
                'p50' => $this->percentileLatencyMs(50.0),
                'p95' => $this->percentileLatencyMs(95.0),
                'p99' => $this->percentileLatencyMs(99.0),
                'max' => $this->maxLatencyMs(),
            ],
            'status_codes' => $this->statusCodes,
            'x_cache' => $this->cacheTally,
        ];
    }
}
