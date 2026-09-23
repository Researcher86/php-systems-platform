<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Workers;

use PhpSystemsPlatform\Domain\OrderLoader;
use RuntimeException;

/**
 * The measurement behind PLAN Step 13: load the same order snapshot both
 * ways and report what each execution model costs.
 *
 * Both loaders answer the same type, so the only difference between the two
 * numbers is where the waiting happened - in this process, one read after
 * another, or spread over worker processes. One untimed warm-up per loader
 * keeps one-off costs (the pool connection, the pool growing to fit the
 * fan-out, a cold connection) out of the average instead of letting them be
 * charged to the first round.
 */
final readonly class OrderLoadBenchmark
{
    public function __construct(
        private OrderLoader $sequential,
        private OrderLoader $concurrent,
    ) {
    }

    /**
     * @return array{rounds: int, sequential_ms: float, concurrent_ms: float, speedup: float}
     */
    public function run(string $orderId, int $rounds): array
    {
        $sequential = $this->measure($this->sequential, $orderId, $rounds);
        $concurrent = $this->measure($this->concurrent, $orderId, $rounds);

        return [
            'rounds' => $rounds,
            'sequential_ms' => round($sequential * 1_000, 3),
            'concurrent_ms' => round($concurrent * 1_000, 3),
            'speedup' => round($sequential / max(1e-9, $concurrent), 2),
        ];
    }

    /**
     * Average seconds per load, over $rounds timed loads.
     */
    private function measure(OrderLoader $loader, string $orderId, int $rounds): float
    {
        if ($loader->load($orderId) === null) {
            throw new RuntimeException(sprintf('Order "%s" cannot be loaded.', $orderId));
        }

        $started = microtime(true);

        for ($round = 0; $round < $rounds; $round++) {
            $loader->load($orderId);
        }

        return (microtime(true) - $started) / $rounds;
    }
}
