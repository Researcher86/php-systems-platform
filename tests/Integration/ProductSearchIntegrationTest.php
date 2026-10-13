<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Tests\Integration;

use PhpSystemsPlatform\Tests\Support\PlatformTestStack;
use PHPUnit\Framework\TestCase;

/**
 * php-mini-search, end to end through the platform (PLAN Step 41): a
 * product is created, found, renamed, found under its new name and not its
 * old one, deleted and no longer found, then created again under the same
 * sku and found again.
 *
 * Everything runs as it does in production: serve owns the search service as
 * a child process, a write publishes `product.index`, the queue consumer runs
 * it through the worker pool, and the job sends the row to the search service
 * over HTTP. The index catches up asynchronously, so each check waits for it
 * — the delay an operator would see, not a sleep.
 */
final class ProductSearchIntegrationTest extends TestCase
{
    private static PlatformTestStack $stack;

    public static function setUpBeforeClass(): void
    {
        self::$stack = PlatformTestStack::acquire();
        self::$stack->startConsumer('product-search-consumer');
    }

    public static function tearDownAfterClass(): void
    {
        PlatformTestStack::release();
    }

    public function testAProductIsFoundThroughEveryChangeToIt(): void
    {
        $sku = 'SKU-SEARCH-' . bin2hex(random_bytes(4));
        $word = 'зарядк' . bin2hex(random_bytes(3));

        $this->put($sku, "Кабель {$word}", 'Быстрая зарядка, 2 метра', '9.90');
        self::assertSame([$sku], $this->waitForSearch($word, [$sku]), 'a created product is found');

        $this->put($sku, "Адаптер {$word}", 'Для старых устройств', '12.00');
        self::assertSame([$sku], $this->waitForSearch("адаптеры {$word}", [$sku]), 'found under its new name');
        self::assertSame([], $this->waitForSearch("кабель {$word}", []), 'not found under its old one');

        [$status, $body] = self::$stack->http('DELETE', "/products/{$sku}");
        self::assertSame(200, $status, $body);
        self::assertSame([], $this->waitForSearch($word, []), 'a deleted product is not found');

        $again = $this->put($sku, "Снова {$word}", '', '5');
        self::assertSame(4, $again['version'], 'the version keeps counting across the delete');
        self::assertSame([$sku], $this->waitForSearch($word, [$sku]), 'created again, found again');
    }

    public function testSearchShowsTheCatalogsRowNotTheIndexs(): void
    {
        $sku = 'SKU-SEARCH-' . bin2hex(random_bytes(4));
        $word = 'тариф' . bin2hex(random_bytes(3));
        $this->put($sku, "Тариф {$word}", '', '19.99');
        $this->waitForSearch($word, [$sku]);

        [$status, $body] = self::$stack->http('GET', '/products/search?q=' . rawurlencode($word));
        $result = json_decode($body, true)['results'][0] ?? [];

        self::assertSame(200, $status);
        self::assertSame(['sku' => $sku, 'title' => "Тариф {$word}", 'description' => '', 'price' => '19.99', 'version' => 1], array_diff_key($result, ['score' => 0]));
    }

    public function testInvalidProductsAndQueriesAreRefused(): void
    {
        self::assertSame(400, self::$stack->http('PUT', '/products/SKU-BAD', '{"title":"","price":"1.00"}')[0]);
        self::assertSame(400, self::$stack->http('PUT', '/products/SKU-BAD', '{"title":"x","price":"-1"}')[0]);
        self::assertSame(400, self::$stack->http('PUT', '/products/bad%20sku', '{"title":"x","price":"1"}')[0]);
        self::assertSame(404, self::$stack->http('DELETE', '/products/SKU-NEVER-WRITTEN')[0]);
        self::assertSame(400, self::$stack->http('GET', '/products/search')[0]);
        self::assertSame(400, self::$stack->http('GET', '/products/search?q=x&limit=101')[0]);
    }

    /**
     * @return array<string, mixed>
     */
    private function put(string $sku, string $title, string $description, string $price): array
    {
        [$status, $body] = self::$stack->http('PUT', "/products/{$sku}", (string) json_encode(['title' => $title, 'description' => $description, 'price' => $price]));
        self::assertSame(200, $status, $body);

        $product = json_decode($body, true);
        self::assertIsArray($product);

        return $product;
    }

    /**
     * Searches until the skus found are $expected or 15 s pass, and returns
     * the last answer: the index is updated by a job, so it lags the write.
     *
     * @param list<string> $expected
     *
     * @return list<string>
     */
    private function waitForSearch(string $query, array $expected): array
    {
        $deadline = microtime(true) + 15.0;

        do {
            [$status, $body] = self::$stack->http('GET', '/products/search?q=' . rawurlencode($query));
            $skus = $status === 200 ? array_column((array) (json_decode($body, true)['results'] ?? []), 'sku') : null;

            if ($skus === $expected) {
                return $skus;
            }

            usleep(100_000);
        } while (microtime(true) < $deadline);

        return $skus ?? ["status {$status}: {$body}"];
    }
}
