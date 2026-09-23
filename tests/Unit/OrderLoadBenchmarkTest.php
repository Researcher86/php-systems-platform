<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Tests\Unit;

use PhpSystemsPlatform\Domain\Order;
use PhpSystemsPlatform\Domain\OrderLoader;
use PhpSystemsPlatform\Domain\OrderSnapshot;
use PhpSystemsPlatform\Domain\OrderStatus;
use PhpSystemsPlatform\Workers\OrderLoadBenchmark;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The arithmetic of the comparison, with loaders whose cost is known: every
 * execution model is reported against the first one, because "twice as fast"
 * is only meaningful relative to the obvious way of doing the work.
 */
final class OrderLoadBenchmarkTest extends TestCase
{
    public function testEveryModelIsMeasuredAgainstTheFirstAsBaseline(): void
    {
        $benchmark = new OrderLoadBenchmark([
            'sequential' => new SlowLoader(4_000),
            'pooled' => new SlowLoader(1_000),
        ]);

        $results = $benchmark->run('any-order', 3);

        self::assertSame(['sequential', 'pooled'], array_column($results, 'name'));
        self::assertSame(1.0, $results[0]['speedup']);
        self::assertGreaterThan(1.5, $results[1]['speedup']);
        self::assertGreaterThan($results[1]['ms'], $results[0]['ms']);
    }

    public function testAModelThatCannotLoadTheOrderFailsTheComparison(): void
    {
        $benchmark = new OrderLoadBenchmark(['sequential' => new SlowLoader(0, found: false)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('missing-order');

        $benchmark->run('missing-order', 1);
    }
}

/**
 * A loader whose only property is how long it takes.
 */
final readonly class SlowLoader implements OrderLoader
{
    public function __construct(
        private int $microseconds,
        private bool $found = true,
    ) {
    }

    public function load(string $id): ?OrderSnapshot
    {
        usleep($this->microseconds);

        if (!$this->found) {
            return null;
        }

        return new OrderSnapshot(
            order: new Order(
                id: $id,
                customer: 'Ada Lovelace',
                amount: '1.00',
                product: 'SKU-STANDARD',
                status: OrderStatus::CREATED,
                createdAt: '2026-01-01T00:00:00Z',
                updatedAt: '2026-01-01T00:00:00Z',
            ),
            customer: null,
            product: null,
            stock: null,
        );
    }
}
