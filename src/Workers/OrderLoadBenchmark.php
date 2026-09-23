<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Workers;

use PhpSystemsPlatform\Domain\OrderLoader;
use RuntimeException;

/**
 * The measurement behind PLAN Steps 13 and 14: load the same order snapshot
 * with every execution model the platform has and report what each one costs.
 *
 * All loaders answer the same type, so the only difference between the
 * numbers is where the waiting happened - one read after another in this
 * process, three processes forked for this one load, or three pool workers
 * that already existed. The first model given is the baseline every other is
 * reported against, because "faster" is only meaningful next to the obvious
 * way of doing the work.
 *
 * One untimed warm-up per loader keeps one-off costs (a pool connection, the
 * pool growing to fit the fan-out, a cold database connection) out of the
 * average instead of charging them to the first round.
 */
final readonly class OrderLoadBenchmark
{
    /**
     * @param array<string, OrderLoader> $loaders baseline first
     */
    public function __construct(
        private array $loaders,
    ) {
    }

    /**
     * @return list<array{name: string, ms: float, speedup: float}> in the
     *         order the loaders were given
     */
    public function run(string $orderId, int $rounds): array
    {
        $baseline = null;
        $results = [];

        foreach ($this->loaders as $name => $loader) {
            $seconds = $this->measure($loader, $orderId, $rounds);
            $baseline ??= $seconds;

            $results[] = [
                'name' => $name,
                'ms' => round($seconds * 1_000, 3),
                'speedup' => round($baseline / max(1e-9, $seconds), 2),
            ];
        }

        return $results;
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
