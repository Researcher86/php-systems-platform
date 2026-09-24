<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Workers;

use PhpSystemsPlatform\Storage\Database;
use PhpSystemsPlatform\Storage\Repositories\CatalogRepository;
use PhpWorkerPool\Protocol\Request;
use PhpWorkerPool\Protocol\Response;
use Throwable;

/**
 * The worker's half of the concurrent snapshot load (PLAN Step 13): one
 * task per independent part of an order's reference data.
 *
 * Each task is a single keyed read. That is the whole point - a part has to
 * be small enough that running it in another process is about overlapping
 * the wait, not about moving work. The worker owns its own database
 * connection, built lazily on first use in that process for the same reason
 * JobExecutor rebuilds its services: a forked worker must never share a
 * socket with its parent.
 *
 * `delay_ms` simulates an enrichment that talks to something slower than a
 * local table, so `orders:compare` can show what the fan-out is worth when
 * the parts actually wait. Both loaders apply it identically; it is 0 on
 * every real path.
 *
 * Every refusal is Response::error(), never an exception: a part the caller
 * described badly is an application outcome, and the pool keeps its worker.
 */
final class CatalogTasks
{
    private const int MAX_DELAY_MS = 1_000;

    private ?Database $database = null;
    private ?CatalogRepository $catalog = null;

    /**
     * @param array<string, mixed> $databaseConfig the `database` config block
     */
    public function __construct(
        private array $databaseConfig,
    ) {
    }

    /**
     * @return \Closure(Request): Response
     */
    public function handler(): \Closure
    {
        return fn (Request $request): Response => match ($request->action) {
            'catalog.customer' => $this->part($request->params, 'name', fn (string $key) => $this->catalog()->findCustomer($key)),
            'catalog.product' => $this->part($request->params, 'sku', fn (string $key) => $this->catalog()->findProduct($key)),
            'catalog.stock' => $this->part($request->params, 'sku', fn (string $key) => $this->catalog()->findStock($key)),
            default => Response::error('unknown_action'),
        };
    }

    /**
     * Read one part by its key and answer the plain row the loader hydrates
     * from - `null` when the catalog has no such entry, which is a valid
     * answer rather than a failure.
     *
     * @param array<string, mixed>        $params
     * @param \Closure(string): ?object   $read
     */
    private function part(array $params, string $keyName, \Closure $read): Response
    {
        $key = $params[$keyName] ?? null;

        if (!is_string($key) || $key === '') {
            return Response::error('bad_params', ['reason' => sprintf('%s must be a non-empty string.', $keyName)]);
        }

        $delay = $params['delay_ms'] ?? 0;

        if (!is_int($delay) || $delay < 0 || $delay > self::MAX_DELAY_MS) {
            return Response::error('bad_params', ['reason' => sprintf('delay_ms must be an int between 0 and %d.', self::MAX_DELAY_MS)]);
        }

        $started = microtime(true);

        try {
            $found = $read($key);
        } catch (Throwable $e) {
            return Response::error('catalog_unavailable', ['reason' => $e->getMessage()]);
        }

        if ($delay > 0) {
            usleep($delay * 1000);
        }

        return Response::of([
            'row' => $found === null ? null : get_object_vars($found),
            'microseconds' => (int) round((microtime(true) - $started) * 1_000_000),
        ]);
    }

    private function catalog(): CatalogRepository
    {
        return $this->catalog ??= new CatalogRepository($this->database());
    }

    private function database(): Database
    {
        return $this->database ??= Database::connect($this->databaseConfig);
    }
}
