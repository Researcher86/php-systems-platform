<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Application\Handlers;

use InvalidArgumentException;
use PhpMiniCache\Sdk\CacheClientException;
use PhpSystemsPlatform\Cache\CacheService;
use PhpSystemsPlatform\Domain\OrderService;
use PhpSystemsPlatform\Http\Request;
use PhpSystemsPlatform\Http\Response;

/**
 * POST /orders - the synchronous write path: parse a {"customer", "amount"}
 * body (plus an optional "product" sku, defaulting to the catalog's standard
 * plan), let OrderService validate and persist it, answer 201 with the order
 * and its Location. A bad payload is a 400; everything below the domain layer
 * (a dead database, for example) is left for the Application boundary to turn
 * into a 500.
 *
 * Cache consistency: populate-on-write. A fresh UUID can never collide with an
 * existing entry, so nothing is ever stale here - the authoritative row is
 * placed into the cache (cache.set) and the very first read is served by it.
 * If the cache cannot answer, the write proceeds as a bypass, never a failure.
 */
final readonly class OrderCreateHandler
{
    public function __construct(
        private OrderService $orders,
        private CacheService $cache,
    ) {
    }

    /**
     * @param array<string, string> $params route parameters (none for /orders)
     */
    public function __invoke(Request $request, array $params): Response
    {
        $payload = $this->decode($request->body);

        if ($payload === null) {
            return Response::json(['error' => 'Malformed JSON payload.'], 400);
        }

        $customer = $payload['customer'] ?? null;
        $amount = $payload['amount'] ?? null;
        $product = $payload['product'] ?? null;

        if (!is_string($customer) || !array_key_exists('amount', $payload)) {
            return Response::json(['error' => 'customer and amount are required.'], 400);
        }

        if ($product !== null && !is_string($product)) {
            return Response::json(['error' => 'product must be a catalog sku.'], 400);
        }

        try {
            $order = $this->orders->createOrder($customer, $amount, $product);
        } catch (InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        }

        try {
            $this->cache->setOrder($order);
        } catch (CacheClientException) {
            $this->cache->counters()->bypasses++;
        }

        return Response::json($order, 201, ['Location' => '/orders/' . $order->id]);
    }

    /**
     * @return array<string, mixed>|null null when the body is not a JSON object
     */
    private function decode(string $body): ?array
    {
        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : null;
    }
}
