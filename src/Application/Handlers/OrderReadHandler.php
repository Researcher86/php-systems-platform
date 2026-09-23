<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Application\Handlers;

use PhpMiniCache\Sdk\CacheClientException;
use PhpSystemsPlatform\Cache\CacheService;
use PhpSystemsPlatform\Domain\OrderService;
use PhpSystemsPlatform\Http\Request;
use PhpSystemsPlatform\Http\Response;

/**
 * GET /orders/{id} - cache-first since the cache phase: a hit answers with
 * the cached representation and never touches the database; a miss reads the
 * authoritative row, refills the cache, and answers. The database stays the
 * source of truth either way, which is also why a cache that cannot answer
 * is a bypass rather than a failure - X-Cache: miss tells the client what
 * actually served the request.
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
        $order = $this->orders->getOrder($id);

        if ($order === null) {
            return Response::json(['error' => 'Order not found.'], 404, ['X-Cache' => 'miss']);
        }

        if (!$bypassing) {
            try {
                $this->cache->setOrder($order);
            } catch (CacheClientException) {
                $this->cache->counters()->bypasses++;
            }
        }

        return Response::json($order, 200, ['X-Cache' => 'miss']);
    }
}
