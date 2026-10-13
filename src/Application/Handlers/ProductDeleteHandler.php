<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Application\Handlers;

use InvalidArgumentException;
use PhpSystemsPlatform\Domain\ProductService;
use PhpSystemsPlatform\Http\Request;
use PhpSystemsPlatform\Http\Response;

/**
 * DELETE /products/{sku} - soft-delete a product. The row stays, so orders
 * that name it still find it and its version keeps growing; search drops it
 * through the `product.index` job the delete publishes. Deleting twice is
 * not an error.
 */
final readonly class ProductDeleteHandler
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
        try {
            $product = $this->products->delete($params['sku']);
        } catch (InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        }

        if ($product === null) {
            return Response::json(['error' => 'Product not found.'], 404);
        }

        return Response::json(['sku' => $product->sku, 'deleted' => true, 'version' => $product->version]);
    }
}
