<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Application\Handlers;

use PhpSystemsPlatform\Http\Request;
use PhpSystemsPlatform\Http\Response;
use PhpSystemsPlatform\Search\SearchClient;
use PhpSystemsPlatform\Search\SearchUnavailable;
use PhpSystemsPlatform\Storage\Repositories\ProductRepository;

/**
 * GET /products/search?q=...&limit=... - full-text search over the catalog.
 *
 * php-mini-search ranks; the database answers. The service returns skus and
 * scores only, and the rows shown come from the platform's own table, so a
 * product renamed a moment ago shows its new title even if the index has not
 * caught up. A sku the index still holds but the catalog has deleted is
 * dropped from the page. The order is the search service's.
 *
 * Search being down, or waiting for its first reindex, is a 503: the rest of
 * the platform keeps working without it.
 */
final readonly class ProductSearchHandler
{
    private const int MAX_LIMIT = 100;

    public function __construct(
        private SearchClient $search,
        private ProductRepository $products,
    ) {
    }

    /**
     * @param array<string, string> $params
     */
    public function __invoke(Request $request, array $params): Response
    {
        $query = $request->query['q'] ?? '';
        $limit = $request->query['limit'] ?? 10;

        if (!is_string($query) || trim($query) === '') {
            return Response::json(['error' => 'q is required.'], 400);
        }
        if (!is_numeric($limit) || (int) $limit < 1 || (int) $limit > self::MAX_LIMIT) {
            return Response::json(['error' => 'limit must be from 1 to ' . self::MAX_LIMIT . '.'], 400);
        }

        try {
            $hits = $this->search->search($query, (int) $limit);
        } catch (SearchUnavailable $e) {
            return Response::json(['error' => 'Search is unavailable.', 'reason' => $e->getMessage()], 503);
        }

        $products = $this->products->findLive(array_column($hits, 'sku'));
        $results = [];
        foreach ($hits as $hit) {
            if (isset($products[$hit['sku']])) {
                $results[] = $products[$hit['sku']]->toArray() + ['score' => $hit['score']];
            }
        }

        return Response::json(['query' => $query, 'results' => $results]);
    }
}
