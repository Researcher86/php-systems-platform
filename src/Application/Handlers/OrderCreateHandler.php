<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Application\Handlers;

use InvalidArgumentException;
use PhpSystemsPlatform\Domain\OrderService;
use PhpSystemsPlatform\Http\Request;
use PhpSystemsPlatform\Http\Response;

/**
 * POST /orders - the synchronous write path: parse a {"customer", "amount"}
 * body, let OrderService validate and persist it, answer 201 with the order
 * and its Location. A bad payload is a 400; everything below the domain layer
 * (a dead database, for example) is left for the Application boundary to turn
 * into a 500.
 */
final readonly class OrderCreateHandler
{
    public function __construct(
        private OrderService $orders,
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

        if (!is_string($customer) || !array_key_exists('amount', $payload)) {
            return Response::json(['error' => 'customer and amount are required.'], 400);
        }

        try {
            $order = $this->orders->createOrder($customer, $amount);
        } catch (InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        }

        return Response::json($order->toArray(), 201, ['Location' => '/orders/' . $order->id]);
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
