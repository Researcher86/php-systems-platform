<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Cache;

use Closure;
use PhpMiniCache\Sdk\CacheClient;
use PhpMiniCache\Sdk\CacheClientException;
use PhpSystemsPlatform\Domain\Order;
use PhpSystemsPlatform\Observability\MetricsRegistry;
use Throwable;

/**
 * The platform's facade over one php-mini-cache connection, plus the
 * read-path bookkeeping.
 *
 * The cache is derived state: it only stores an order's JSON keyed by id,
 * and the database stays the source of truth. Client failures surface as
 * CacheClientException; degrading from them is the caller's decision.
 *
 * Disabled (CACHE_ENABLED=0) is not the same as down: a disabled cache
 * answers every lookup with a miss without touching a socket, so "what does
 * this read cost without a cache" is measured without timeout noise.
 */
final readonly class CacheService
{
    /** Long enough for the demo's read to hit; a backstop for stale data. */
    public const int ORDER_TTL_SECONDS = 60;

    private const string ORDER_KEY_PREFIX = 'order:';

    public function __construct(
        private CacheClient $client,
        private CacheCounters $counters,
        private ?MetricsRegistry $metrics = null,
        private bool $enabled = true,
    ) {
    }

    /**
     * @param array<string, mixed> $config host, port, timeout, enabled
     */
    public static function fromConfig(array $config, ?MetricsRegistry $metrics = null): self
    {
        return new self(
            new CacheClient(
                host: $config['host'],
                port: $config['port'],
                timeoutSeconds: $config['timeout'],
            ),
            new CacheCounters(),
            $metrics,
            (bool) ($config['enabled'] ?? true),
        );
    }

    public function counters(): CacheCounters
    {
        return $this->counters;
    }

    /**
     * The cached payload for an order, or null on a miss. Every lookup
     * counts exactly once, as a hit or a miss - a disabled cache and an
     * entry that no longer parses (not servable) are misses too - so
     * hits + misses always equals lookups. An unreachable cache counts
     * neither: it throws, and the caller records the bypass.
     *
     * @return array<string, mixed>|null
     *
     * @throws CacheClientException the cache is unreachable
     */
    public function getOrder(string $id): ?array
    {
        $json = $this->enabled ? $this->client->get(self::ORDER_KEY_PREFIX . $id) : null;
        $order = $json === null ? null : json_decode($json, true);

        if (!is_array($order)) {
            $this->recordMiss();

            return null;
        }

        $this->counters->hits++;
        $this->metrics?->increment(MetricsRegistry::CACHE_HITS);
        $this->metrics?->increment(MetricsRegistry::CACHE_OPERATIONS);

        return $order;
    }

    /**
     * Store an order. Its public fields are the representation, so a hit
     * answers with the same body a database-served read does (only X-Cache
     * differs). A disabled cache is neither written nor counted as written.
     *
     * @throws CacheClientException the cache is unreachable - the caller
     *                              serves the database copy anyway
     */
    public function setOrder(Order $order): void
    {
        if (!$this->enabled) {
            return;
        }

        $this->client->set(
            self::ORDER_KEY_PREFIX . $order->id,
            $this->encode($order),
            self::ORDER_TTL_SECONDS,
        );
        $this->counters->sets++;
        $this->metrics?->increment(MetricsRegistry::CACHE_OPERATIONS);
    }

    /**
     * The cache-aside fill: load an order from the source of truth and cache
     * it - unless the key was written in the meantime.
     *
     * A plain "read the row, then SET" loses a race to any writer: a reader
     * loads the old row, a writer updates it and deletes the key, and the
     * reader's SET then stores the old row until the TTL runs out. So the key
     * is WATCHed before $load runs and filled with setIfUnchanged(): a DEL
     * (or SET) by anyone in between - even of the key that is not there,
     * which is the point - aborts the fill. The database copy is still
     * returned; only the stale cache write is dropped.
     *
     * Degrades like every other cache call, but internally: an unreachable
     * cache is counted as a bypass and $load's result returned regardless,
     * so the caller only deals with the database.
     *
     * @param Closure(): ?Order $load
     */
    public function loadAndFillOrder(string $id, Closure $load): ?Order
    {
        if (!$this->enabled) {
            return $load();
        }

        $key = self::ORDER_KEY_PREFIX . $id;

        try {
            $this->client->watch($key);
        } catch (CacheClientException) {
            $this->counters->bypasses++;

            return $load();
        }

        try {
            $order = $load();
        } catch (Throwable $e) {
            $this->unwatchQuietly();

            throw $e;
        }

        try {
            if ($order === null) {
                $this->client->unwatch();
            } elseif ($this->client->setIfUnchanged($key, $this->encode($order), self::ORDER_TTL_SECONDS)) {
                $this->counters->sets++;
                $this->metrics?->increment(MetricsRegistry::CACHE_OPERATIONS);
            } else {
                $this->counters->abandonedFills++;
            }
        } catch (CacheClientException) {
            $this->counters->bypasses++;
        }

        return $order;
    }

    /**
     * Invalidate an entry after its row changed. A no-op when disabled.
     *
     * @throws CacheClientException the cache is unreachable
     */
    public function deleteOrder(string $id): void
    {
        if (!$this->enabled) {
            return;
        }

        $this->client->delete(self::ORDER_KEY_PREFIX . $id);
        $this->counters->deletes++;
        $this->metrics?->increment(MetricsRegistry::CACHE_OPERATIONS);
    }

    public function close(): void
    {
        $this->client->close();
    }

    private function encode(Order $order): string
    {
        return (string) json_encode($order, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** A watch left behind would only abort a later fill; losing it is harmless. */
    private function unwatchQuietly(): void
    {
        try {
            $this->client->unwatch();
        } catch (CacheClientException) {
        }
    }

    private function recordMiss(): void
    {
        $this->counters->misses++;
        $this->metrics?->increment(MetricsRegistry::CACHE_MISSES);
        $this->metrics?->increment(MetricsRegistry::CACHE_OPERATIONS);
    }
}
