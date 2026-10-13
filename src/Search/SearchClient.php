<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Search;

use CurlHandle;
use RuntimeException;

/**
 * The platform's side of php-mini-search's HTTP contract (its docs/API.md),
 * and the only place the platform speaks to it. Plain curl and JSON: the
 * platform never imports a php-mini-search class, so the service stays a
 * separate process behind a protocol, like any other service would be.
 *
 * Timeouts are the contract's: 1 s to connect, 5 s for an answer. Nothing
 * is retried here — a write that fails throws SearchUnavailable, the job that
 * sent it fails its attempt, and the queue retries it. That is safe because
 * every write carries the row's source version: a duplicate answers
 * `applied: false` and changes nothing.
 */
final readonly class SearchClient
{
    public function __construct(
        private string $baseUrl,
        private string $token,
        private float $connectTimeout = 1.0,
        private float $timeout = 5.0,
    ) {
    }

    /**
     * @param array<string, mixed> $config the `search` config block
     */
    public static function fromConfig(array $config): self
    {
        return new self(
            sprintf('http://%s:%d', (string) $config['host'], (int) $config['port']),
            (string) $config['token'],
            (float) ($config['connect_timeout'] ?? 1.0),
            (float) ($config['timeout'] ?? 5.0),
        );
    }

    /**
     * Index a product at its source version. False when the service already
     * holds that version or a newer one (or a newer delete).
     */
    public function put(string $sku, int $version, string $title, string $body, ?int $generation = null): bool
    {
        $document = ['source_version' => $version, 'title' => $title, 'body' => $body];
        if ($generation !== null) {
            $document['generation'] = $generation;
        }

        return $this->expectOk('PUT', '/documents/' . rawurlencode($sku), $document)['applied'] === true;
    }

    public function delete(string $sku, int $version): bool
    {
        return $this->expectOk('DELETE', '/documents/' . rawurlencode($sku) . '?source_version=' . $version)['applied'] === true;
    }

    /**
     * The skus matching every word of the query, best first. An empty or
     * punctuation-only query matches nothing rather than failing.
     *
     * @return list<array{sku: string, score: float}>
     */
    public function search(string $query, int $limit = 10): array
    {
        [$status, $body] = $this->request('POST', '/search', ['query' => $query, 'limit' => $limit]);

        if ($status === 422 && in_array($this->errorCode($body), ['empty_query', 'too_many_terms'], true)) {
            return [];
        }
        if ($status !== 200) {
            throw new SearchUnavailable(sprintf('Search answered %d (%s).', $status, $this->errorCode($body) ?? 'no code'));
        }

        $results = [];
        foreach ((array) ($body['results'] ?? []) as $result) {
            if (is_array($result) && is_string($result['id'] ?? null)) {
                $results[] = ['sku' => $result['id'], 'score' => (float) ($result['score'] ?? 0.0)];
            }
        }

        return $results;
    }

    /**
     * Null when the service is ready to search; otherwise why it is not
     * (`first_start`, `log_corrupted`).
     */
    public function notReadyReason(): ?string
    {
        [$status, $body] = $this->request('GET', '/health/ready');

        if ($status === 200) {
            return null;
        }
        if ($status === 503 && is_string($body['reason'] ?? null)) {
            return $body['reason'];
        }

        throw new SearchUnavailable(sprintf('Search readiness answered %d.', $status));
    }

    public function beginReindex(): int
    {
        return (int) $this->expectOk('POST', '/reindex')['generation'];
    }

    /**
     * @return int how many documents the generation did not touch, and were removed
     */
    public function completeReindex(int $generation): int
    {
        return (int) $this->expectOk('POST', "/reindex/{$generation}/complete")['removed'];
    }

    /**
     * @param non-empty-string          $method
     * @param array<string, mixed>|null $json
     *
     * @return array<string, mixed>
     */
    private function expectOk(string $method, string $path, ?array $json = null): array
    {
        [$status, $body] = $this->request($method, $path, $json);

        if ($status !== 200) {
            throw new SearchUnavailable(sprintf('%s %s answered %d (%s).', $method, $path, $status, $this->errorCode($body) ?? 'no code'));
        }

        return $body;
    }

    /**
     * @param non-empty-string          $method
     * @param array<string, mixed>|null $json
     *
     * @return array{int, array<string, mixed>}
     */
    private function request(string $method, string $path, ?array $json = null): array
    {
        $headers = ['Authorization: Bearer ' . $this->token];
        $handle = curl_init($this->baseUrl . $path);
        if (!$handle instanceof CurlHandle) {
            throw new RuntimeException('Could not create a curl handle.');
        }

        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT_MS => (int) ($this->connectTimeout * 1000),
            CURLOPT_TIMEOUT_MS => (int) ($this->timeout * 1000),
        ];
        if ($json !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $headers[] = 'Content-Type: application/json';
        }
        $options[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($handle, $options);

        // No curl_close(): the handle is freed when it goes out of scope, and
        // curl_close() is deprecated in PHP 8.5.
        $raw = curl_exec($handle);
        if (!is_string($raw)) {
            throw new SearchUnavailable(sprintf('%s %s failed: %s', $method, $path, curl_error($handle)));
        }

        $body = json_decode($raw, true);

        /** @var array<string, mixed> $decoded */
        $decoded = is_array($body) ? $body : [];

        return [(int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $decoded];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function errorCode(array $body): ?string
    {
        $error = $body['error'] ?? null;

        return is_array($error) && is_string($error['code'] ?? null) ? $error['code'] : null;
    }
}
