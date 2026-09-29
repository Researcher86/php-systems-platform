<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Application\Handlers;

use InvalidArgumentException;
use PhpMiniCache\Sdk\CacheClientException;
use PhpSystemsPlatform\Cache\CacheService;
use PhpSystemsPlatform\Domain\OrderService;
use PhpSystemsPlatform\Http\Request;
use PhpSystemsPlatform\Http\Response;
use PhpSystemsPlatform\Observability\Trace;
use PhpSystemsPlatform\Queue\BackpressurePolicy;

/**
 * POST /orders - the synchronous write path: parse {"customer", "amount",
 * "product"?}, let OrderService validate and persist it, answer 201 with the
 * order and its Location. A bad payload is a 400; failures below the domain
 * (a dead database) are left for the Application boundary to turn into 500.
 *
 * Backpressure is checked BEFORE anything else, so an overloaded queue is a
 * 429 with nothing written and nothing enqueued - reject, not block or drop.
 * A null policy means no queue to protect.
 *
 * Cache: populate-on-write. A fresh UUID cannot collide with an existing
 * entry, so the new row is cached as-is; an unreachable cache is a bypass,
 * never a failed write.
 */
final readonly class OrderCreateHandler
{
    public function __construct(
        private OrderService $orders,
        private CacheService $cache,
        private ?BackpressurePolicy $backpressure = null,
        private ?Trace $trace = null,
    ) {
    }

    /**
     * @param array<string, string> $params route parameters (none for /orders)
     */
    public function __invoke(Request $request, array $params): Response
    {
        $decision = $this->backpressure?->evaluate();

        if ($decision?->atCapacity === true) {
            return Response::json([
                'error' => 'Queue is at capacity.',
                'queueDepth' => $decision->depth,
                'queueMaxSize' => $decision->maxSize,
            ], 429, ['Retry-After' => '1']);
        }

        $payload = json_decode($request->body, true);

        if (!is_array($payload)) {
            return Response::json(['error' => 'Malformed JSON payload.'], 400);
        }

        $customer = $payload['customer'] ?? null;
        $product = $payload['product'] ?? null;

        if (!is_string($customer) || !array_key_exists('amount', $payload)) {
            return Response::json(['error' => 'customer and amount are required.'], 400);
        }

        if ($product !== null && !is_string($product)) {
            return Response::json(['error' => 'product must be a catalog sku.'], 400);
        }

        try {
            // The active request_id rides along into the job payload so the
            // worker can re-open this request's trace scope.
            $order = $this->orders->createOrder($customer, $payload['amount'], $product, $this->trace?->activeRequestId());
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
}
