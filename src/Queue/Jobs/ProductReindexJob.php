<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Queue\Jobs;

use PhpSystemsPlatform\Queue\Job;
use PhpSystemsPlatform\Queue\JobContext;
use PhpSystemsPlatform\Search\SearchClient;
use PhpSystemsPlatform\Storage\Repositories\ProductRepository;
use RuntimeException;

/**
 * A full reindex: open a generation, send every live product with it, then
 * complete it. php-mini-search then drops whatever the generation did not
 * touch — the products whose delete never reached it — and leaves
 * `reindex_required` if it was in it.
 *
 * serve publishes this job when the search service reports that it has
 * never been filled or has lost its log; `search:reindex` runs the same
 * thing in the foreground. A retried attempt simply opens a new generation,
 * which cancels the one the failed attempt left open.
 */
final readonly class ProductReindexJob implements Job
{
    public const string TYPE = 'product.reindex';

    public function execute(JobContext $context): void
    {
        if ($context->products === null || $context->search === null) {
            throw new RuntimeException('product.reindex needs the products repository and the search client.');
        }

        self::run($context->products, $context->search);
    }

    /**
     * @return array{generation: int, sent: int, removed: int}
     */
    public static function run(ProductRepository $products, SearchClient $search): array
    {
        $generation = $search->beginReindex();
        $sent = 0;

        foreach ($products->allLive() as $product) {
            $search->put($product->sku, $product->version, $product->title, $product->description, $generation);
            $sent++;
        }

        return ['generation' => $generation, 'sent' => $sent, 'removed' => $search->completeReindex($generation)];
    }
}
