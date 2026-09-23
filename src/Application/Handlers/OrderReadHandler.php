<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Application\Handlers;

use PhpSystemsPlatform\Domain\OrderService;
use PhpSystemsPlatform\Http\Request;
use PhpSystemsPlatform\Http\Response;

/**
 * GET /orders/{id} - the synchronous read path. The cache phase replaces the
 * database-first lookup with cache-first/cache-miss->database->set without
 * changing this handler's contract.
 */
final readonly class OrderReadHandler
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
        $order = $this->orders->getOrder($params['id']);

        if ($order === null) {
            return Response::json(['error' => 'Order not found.'], 404);
        }

        return Response::json($order->toArray());
    }
}
