<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Application\Handlers;

use InvalidArgumentException;
use PhpSystemsPlatform\Domain\ProductService;
use PhpSystemsPlatform\Http\Request;
use PhpSystemsPlatform\Http\Response;

/**
 * PUT /products/{sku} - create a product, or replace it (a deleted one comes
 * back). Body: {"title", "description", "price"}. Answers the row as written,
 * with its new version; the search index catches up through the
 * `product.index` job the write publishes.
 */
final readonly class ProductPutHandler
{
    public function __construct(
        private ProductService $products,
    ) {
    }

    /**
     * @param array<string, string> $params route parameters (sku)
     */
    public function __invoke(Request $request, array $params): Response
    {
        $payload = json_decode($request->body, true);

        if (!is_array($payload)) {
            return Response::json(['error' => 'The body must be a JSON object.'], 400);
        }

        try {
            $product = $this->products->put($params['sku'], $payload['title'] ?? null, $payload['description'] ?? '', $payload['price'] ?? null);
        } catch (InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        }

        return Response::json($product->toArray());
    }
}
