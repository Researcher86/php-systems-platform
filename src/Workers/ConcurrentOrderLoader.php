<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Workers;

use PhpSystemsPlatform\Domain\Customer;
use PhpSystemsPlatform\Domain\OrderLoader;
use PhpSystemsPlatform\Domain\OrderService;
use PhpSystemsPlatform\Domain\OrderSnapshot;
use PhpSystemsPlatform\Domain\Product;
use PhpSystemsPlatform\Domain\StockLevel;
use PhpWorkerPool\Protocol\Request as WorkerRequest;

/**
 * The same snapshot as SequentialOrderLoader, with the independent reads in
 * flight at the same time on worker processes (PLAN Step 13).
 *
 * The shape of the work decides the shape of the code: the order must be
 * read first because the other three are keyed by what it says, and only
 * then are customer, product and stock genuinely independent of each other.
 * Those three go to the pool as one fan-out - three requests sent before any
 * of them is awaited - and are collected all-or-fail, because a snapshot
 * with a part silently missing would be indistinguishable from a snapshot
 * whose catalog row does not exist.
 *
 * What this buys is overlap of the waiting, not more database throughput:
 * the mini database's server is a single event loop, so three queries do not
 * execute in parallel inside it. Any part that waits on something else - the
 * simulated external latency here, a remote service in a real system - is
 * where the fan-out pays, and three trivial local reads are where it does
 * not: the pool round trip then costs more than the overlap saves. That
 * measurement is what `orders:compare` prints.
 */
final readonly class ConcurrentOrderLoader implements OrderLoader
{
    public function __construct(
        private OrderService $orders,
        private ConcurrentTaskRunner $runner,
        private int $simulatedLatencyMs = 0,
    ) {
    }

    public function load(string $id): ?OrderSnapshot
    {
        $order = $this->orders->getOrder($id);

        if ($order === null) {
            return null;
        }

        $answers = $this->runner->run(
            new WorkerRequest('catalog.customer', ['name' => $order->customer, 'delay_ms' => $this->simulatedLatencyMs]),
            new WorkerRequest('catalog.product', ['sku' => $order->product, 'delay_ms' => $this->simulatedLatencyMs]),
            new WorkerRequest('catalog.stock', ['sku' => $order->product, 'delay_ms' => $this->simulatedLatencyMs]),
        );

        $customer = $this->row($answers[0] ?? null);
        $product = $this->row($answers[1] ?? null);
        $stock = $this->row($answers[2] ?? null);

        return new OrderSnapshot(
            order: $order,
            customer: $customer === null ? null : Customer::fromRow($customer),
            product: $product === null ? null : Product::fromRow($product),
            stock: $stock === null ? null : StockLevel::fromRow($stock),
        );
    }

    /**
     * The row a worker answered with, if the catalog had one.
     *
     * @param array<string, mixed>|null $answer
     *
     * @return array<string, mixed>|null
     */
    private function row(?array $answer): ?array
    {
        $row = $answer['row'] ?? null;

        return is_array($row) ? $row : null;
    }
}
