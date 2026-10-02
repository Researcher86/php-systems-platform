<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Domain;

use InvalidArgumentException;
use PhpJobQueue\Producer\Producer;
use PhpSystemsPlatform\Queue\Jobs\OrderCreatedJob;
use PhpSystemsPlatform\Storage\Repositories\OrderRepository;
use Ramsey\Uuid\Uuid;

/**
 * The synchronous order lifecycle on top of the repository: validates the
 * domain invariants, generates the UUID v7 id and books the timestamps, so
 * handlers stay a thin HTTP translation layer.
 *
 * A write persists the authoritative row FIRST and only then dispatches the
 * job, so a job never names an order the database does not have. Without a
 * producer the write is a plain persist; handlers never know a queue exists.
 */
final readonly class OrderService
{
    /**
     * The catalog sku an order is for when the request does not name one.
     * Seeded by Storage\Migrator, so a default order always has a product
     * and a stock level to load.
     */
    public const string DEFAULT_PRODUCT = 'SKU-STANDARD';

    public function __construct(
        private OrderRepository $orders,
        private ?Producer $producer = null,
    ) {
    }

    /**
     * @param string|null $requestId the trace request_id of the HTTP request
     *                               this write answers, copied into the job
     *                               payload so the worker can re-open that
     *                               scope; null outside a traced request
     *
     * @throws InvalidArgumentException on an invalid customer, amount or product
     */
    public function createOrder(string $customer, mixed $amount, ?string $product = null, ?string $requestId = null): Order
    {
        $now = $this->now();

        $order = new Order(
            id: Uuid::uuid7()->toString(),
            customer: $this->normalizeCustomer($customer),
            amount: $this->normalizeAmount($amount),
            product: $this->normalizeProduct($product),
            status: OrderStatus::CREATED,
            createdAt: $now,
            updatedAt: $now,
        );

        if (!$this->orders->create($order)) {
            throw new \RuntimeException('Could not persist the order.');
        }

        // The idempotency key names the OPERATION ("this order was
        // created"), not a delivery, so every redelivery of this job is
        // recognized by the queue-side IdempotencyGuard.
        $payload = ['order_id' => $order->id];

        if ($requestId !== null) {
            $payload['request_id'] = $requestId;
        }

        $this->producer?->dispatch(
            OrderCreatedJob::TYPE,
            $payload,
            idempotencyKey: 'order.created:' . $order->id,
        );

        return $order;
    }

    public function getOrder(string $id): ?Order
    {
        return $this->orders->find($id);
    }

    public function updateOrderStatus(string $id, OrderStatus $status): ?Order
    {
        if (!$this->orders->updateStatus($id, $status, $this->now())) {
            return null;
        }

        return $this->orders->find($id);
    }

    private function normalizeCustomer(string $customer): string
    {
        $customer = trim($customer);

        if ($customer === '') {
            throw new InvalidArgumentException('customer must not be empty.');
        }

        // Bytes, not characters: the column is VARCHAR(255) and the mini
        // database caps it in bytes, so a longer name would fail the INSERT.
        if (strlen($customer) > 255) {
            throw new InvalidArgumentException('customer must not exceed 255 bytes.');
        }

        return $customer;
    }

    /**
     * A sku is a catalog key, not free text: it keys three later loads, so an
     * unusable one is refused here rather than becoming three empty reads.
     * An absent sku is the default product.
     */
    private function normalizeProduct(?string $product): string
    {
        $product = trim($product ?? '');

        if ($product === '') {
            return self::DEFAULT_PRODUCT;
        }

        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/', $product) !== 1) {
            throw new InvalidArgumentException('product must be a catalog sku.');
        }

        return $product;
    }

    /**
     * Accept a JSON number or numeric string and normalize it to the
     * fixed-scale "12.34" shape DECIMAL(10,2) stores, rejecting anything
     * outside its integer-digit capacity.
     *
     * Normalization is what makes equal amounts store equal: "+5", "005" and
     * 5 all become "5.00". Extra fraction digits are cut: a number is
     * rounded by sprintf(), a string is truncated.
     */
    private function normalizeAmount(mixed $value): string
    {
        if (is_int($value) || is_float($value)) {
            $value = sprintf('%.2F', $value);
        }

        if (!is_string($value) || preg_match('/^[+-]?\d+(?:\.\d+)?$/', $value) !== 1) {
            throw new InvalidArgumentException('amount must be a number.');
        }

        if (str_starts_with($value, '-')) {
            throw new InvalidArgumentException('amount must not be negative.');
        }

        $parts = explode('.', ltrim($value, '+'));
        // Leading zeros are dropped before the capacity check counts digits.
        $integer = ltrim($parts[0], '0') ?: '0';

        if (strlen($integer) > 8) {
            throw new InvalidArgumentException('amount must not exceed 99999999.99.');
        }

        $fraction = isset($parts[1]) ? str_pad(substr($parts[1], 0, 2), 2, '0') : '00';

        return $integer . '.' . $fraction;
    }

    private function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}
