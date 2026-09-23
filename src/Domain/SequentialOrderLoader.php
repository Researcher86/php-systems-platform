<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Domain;

use PhpSystemsPlatform\Storage\Repositories\CatalogRepository;

/**
 * The obvious way to build a snapshot: four reads, one after another, in one
 * process. The order first - the other three need its customer and sku, so
 * that read is a genuine dependency, not a choice - then the customer, the
 * product and the stock level.
 *
 * This is the baseline the concurrent loader is measured against. It is also
 * the loader a queue worker uses: a worker that is already a process in a
 * pool has nothing to gain from asking that same pool to do its waiting.
 *
 * `simulatedLatencyMs` models an enrichment that talks to something slower
 * than a local table - the "external/simulated operation" of the plan's
 * concurrency diagram. It is a measurement knob for `orders:compare`, applied
 * identically by both loaders so the comparison stays fair; it is 0 on every
 * real path.
 */
final readonly class SequentialOrderLoader implements OrderLoader
{
    public function __construct(
        private OrderService $orders,
        private CatalogRepository $catalog,
        private int $simulatedLatencyMs = 0,
    ) {
    }

    public function load(string $id): ?OrderSnapshot
    {
        $order = $this->orders->getOrder($id);

        if ($order === null) {
            return null;
        }

        $customer = $this->catalog->findCustomer($order->customer);
        $this->pause();

        $product = $this->catalog->findProduct($order->product);
        $this->pause();

        $stock = $this->catalog->findStock($order->product);
        $this->pause();

        return new OrderSnapshot($order, $customer, $product, $stock);
    }

    private function pause(): void
    {
        if ($this->simulatedLatencyMs > 0) {
            usleep($this->simulatedLatencyMs * 1000);
        }
    }
}
