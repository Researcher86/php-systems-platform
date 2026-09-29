<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Application\Handlers;

use PhpMiniCache\Sdk\CacheClientException;
use PhpSystemsPlatform\Cache\CacheService;
use PhpSystemsPlatform\Domain\Order;
use PhpSystemsPlatform\Domain\OrderService;
use PhpSystemsPlatform\Http\Request;
use PhpSystemsPlatform\Http\Response;

/**
 * GET /orders/{id} - cache-aside: a hit answers from the cache without
 * touching the database; a miss reads the row, refills the cache, answers.
 * An unreachable cache is a bypass, not a failure - the database is the
 * source of truth, and X-Cache: miss says it served the request.
 */
final readonly class OrderReadHandler
{
    public function __construct(
        private OrderService $orders,
        private CacheService $cache,
    ) {
    }

    /**
     * @param array<string, string> $params route parameters (id)
     */
    public function __invoke(Request $request, array $params): Response
    {
        $id = $params['id'];

        try {
            $cached = $this->cache->getOrder($id);
        } catch (CacheClientException) {
            $this->cache->counters()->bypasses++;

            return $this->fromDatabase($id, bypassing: true);
        }

        if ($cached !== null) {
            return Response::json($cached, 200, ['X-Cache' => 'hit']);
        }

        return $this->fromDatabase($id);
    }

    private function fromDatabase(string $id, bool $bypassing = false): Response
    {
        // After a miss the fill is guarded (WATCH + check-and-set), so a
        // worker updating the row and deleting the key while we read it
        // cannot leave the old row cached. After a bypass the cache is
        // unreachable, so there is nothing to fill.
        $order = $bypassing
            ? $this->orders->getOrder($id)
            : $this->cache->loadAndFillOrder($id, fn (): ?Order => $this->orders->getOrder($id));

        if ($order === null) {
            return Response::json(['error' => 'Order not found.'], 404, ['X-Cache' => 'miss']);
        }

        return Response::json($order, 200, ['X-Cache' => 'miss']);
    }
}
