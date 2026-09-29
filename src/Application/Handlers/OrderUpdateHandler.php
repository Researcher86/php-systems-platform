<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Application\Handlers;

use PhpMiniCache\Sdk\CacheClientException;
use PhpSystemsPlatform\Cache\CacheService;
use PhpSystemsPlatform\Domain\OrderService;
use PhpSystemsPlatform\Domain\OrderStatus;
use PhpSystemsPlatform\Http\Request;
use PhpSystemsPlatform\Http\Response;

/**
 * PUT /orders/{id} - move an order to a new status. Unknown statuses are a
 * 400, missing orders a 404. Any status may follow any other; there is no
 * transition table.
 *
 * Cache: invalidate-on-write. The cached copy is deleted rather than
 * rewritten, so the next read refills from the authoritative row. An
 * unreachable cache is a bypass, never a failed write.
 */
final readonly class OrderUpdateHandler
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
        $payload = json_decode($request->body, true);

        if (!is_array($payload) || !isset($payload['status']) || !is_string($payload['status'])) {
            return Response::json(['error' => 'status is required.'], 400);
        }

        $status = OrderStatus::tryFrom(strtolower($payload['status']));

        if ($status === null) {
            return Response::json(['error' => 'Unknown status.'], 400);
        }

        $order = $this->orders->updateOrderStatus($params['id'], $status);

        if ($order === null) {
            return Response::json(['error' => 'Order not found.'], 404);
        }

        try {
            $this->cache->deleteOrder($order->id);
        } catch (CacheClientException) {
            $this->cache->counters()->bypasses++;
        }

        return Response::json($order);
    }
}
