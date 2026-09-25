<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Cache;

use PhpMiniCache\Sdk\CacheClient;
use PhpMiniCache\Sdk\CacheClientException;
use PhpSystemsPlatform\Domain\Order;
use PhpSystemsPlatform\Observability\MetricsRegistry;

/**
 * The platform's facade over one shared php-mini-cache connection, plus the
 * read-path bookkeeping PLAN asks for.
 *
 * The database is the source of truth; this cache is derived state. It only
 * ever stores an order's JSON, keyed by id, so a hit can skip the repository
 * and a miss can repopulate it from the authoritative row. Cache-client
 * failures surface as CacheClientException and are the handler's decision to
 * degrade from, never this service's.
 */
final readonly class CacheService
{
    /**
     * The lifetime of an order entry. Long enough that the demo read hits
     * the cache, short enough that a repaired-after-write-through entry (a
     * future phase) would eventually age out on its own.
     */
    public const ORDER_TTL_SECONDS = 60;

    private const ORDER_KEY_PREFIX = 'order:';

    public function __construct(
        private CacheClient $client,
        private CacheCounters $counters,
        private ?MetricsRegistry $metrics = null,
    ) {
    }

    /** @param array<string, mixed> $config host, port, timeout */
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
        );
    }

    public function counters(): CacheCounters
    {
        return $this->counters;
    }

    /**
     * The cached payload for an order, or null on a miss. A hit counts once;
     * a miss counts once too, so the two add up to every lookup made through
     * this method. A stored value that no longer parses as an object counts
     * as a miss: it is not authoritative, so it must not be served.
     *
     * @return array<string, mixed>|null
     *
     * @throws CacheClientException the cache is unreachable - a bypass, not a payload
     */
    public function getOrder(string $id): ?array
    {
        $json = $this->client->get(self::ORDER_KEY_PREFIX . $id);

        if ($json === null) {
            $this->counters->misses++;
            $this->metrics?->increment(MetricsRegistry::CACHE_MISSES);
            $this->metrics?->increment(MetricsRegistry::CACHE_OPERATIONS);

            return null;
        }

        $order = json_decode($json, true);

        if (!is_array($order)) {
            $this->counters->misses++;
            $this->metrics?->increment(MetricsRegistry::CACHE_MISSES);
            $this->metrics?->increment(MetricsRegistry::CACHE_OPERATIONS);

            return null;
        }

        $this->counters->hits++;
        $this->metrics?->increment(MetricsRegistry::CACHE_HITS);
        $this->metrics?->increment(MetricsRegistry::CACHE_OPERATIONS);

        return $order;
    }

    /**
     * Refill an entry after a miss. The order's own public fields are the
     * representation - the same bytes a direct database read would answer
     * with - so a hit is indistinguishable from a repository read.
     *
     * @throws CacheClientException the cache is unreachable - the caller
     *                              serves the database copy anyway
     */
    public function setOrder(Order $order): void
    {
        $this->client->set(
            self::ORDER_KEY_PREFIX . $order->id,
            (string) json_encode($order, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            self::ORDER_TTL_SECONDS,
        );
        $this->counters->sets++;
        $this->metrics?->increment(MetricsRegistry::CACHE_OPERATIONS);
    }

    /**
     * Remove an entry - the write-path counterpart to setOrder(), used by
     * the invalidation phase when a row changes.
     *
     * @throws CacheClientException the cache is unreachable
     */
    public function deleteOrder(string $id): void
    {
        $this->client->delete(self::ORDER_KEY_PREFIX . $id);
        $this->counters->deletes++;
        $this->metrics?->increment(MetricsRegistry::CACHE_OPERATIONS);
    }

    public function close(): void
    {
        $this->client->close();
    }
}
