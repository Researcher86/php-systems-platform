<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Application\Handlers;

use PhpSystemsPlatform\Domain\OrderService;
use PhpSystemsPlatform\Domain\OrderStatus;
use PhpSystemsPlatform\Http\Request;
use PhpSystemsPlatform\Http\Response;

/**
 * PUT /orders/{id} - the synchronous update path: move an order to a new
 * status ("processing", "completed", "cancelled"). Invalid statuses are a
 * 400, missing orders a 404.
 */
final readonly class OrderUpdateHandler
{
    public function __construct(
        private OrderService $orders,
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

        return Response::json($order);
    }
}
