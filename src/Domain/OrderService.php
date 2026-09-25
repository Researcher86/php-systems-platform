<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Domain;

use InvalidArgumentException;
use PhpJobQueue\Producer\Producer;
use PhpSystemsPlatform\Queue\Jobs\OrderCreatedJob;
use PhpSystemsPlatform\Storage\Repositories\OrderRepository;
use Ramsey\Uuid\Uuid;

/**
 * The whole synchronous order lifecycle, on top of the repository: validates
 * the domain invariants (what a customer and an amount are), generates the
 * UUID v7 id, books the timestamps, and lets the handler stay a thin HTTP
 * translation layer. Writing an order is strictly: persist the authoritative
 * row first, then offer the fact up to the queue through the producer seam.
 * The seam is optional - the database stays the source of truth for anything
 * that is handed a service without a producer (tests, a future read-only
 * command) - and the handlers never know a queue exists.
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
     * @param string|null $requestId the PLAN Step 24 request_id of the HTTP
     *                               request this write answers, echoed into
     *                               the job payload so the worker can
     *                               re-open that request's trace scope; null
     *                               when the caller is not a request at all
     *                               (a test, a command), whose job simply
     *                               carries no request_id
     */
    public function createOrder(string $customer, mixed $amount, ?string $product = null, ?string $requestId = null): Order
    {
        $customer = $this->normalizeCustomer($customer);
        $amount = $this->normalizeAmount($amount);
        $product = $this->normalizeProduct($product);

        $now = $this->now();

        $order = new Order(
            id: Uuid::uuid7()->toString(),
            customer: $customer,
            amount: $amount,
            product: $product,
            status: OrderStatus::CREATED,
            createdAt: $now,
            updatedAt: $now,
        );

        if (!$this->orders->create($order)) {
            throw new \RuntimeException('Could not persist the order.');
        }

        // The background job is keyed by the OPERATION it represents, not by
        // a delivery: `order.created:<order id>` means "this order was
        // created", so whichever of this order's deliveries arrives, the
        // queue-side IdempotencyGuard (PLAN Step 20) answers the same way -
        // and a service without a producer never sees a key at all. The
        // job's payload carries the request_id it originated from (PLAN
        // Step 24), faithfully and only when the write route knew one.
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

    public function deleteOrder(string $id): bool
    {
        return $this->orders->delete($id);
    }

    private function normalizeCustomer(string $customer): string
    {
        $customer = trim($customer);

        if ($customer === '') {
            throw new InvalidArgumentException('customer must not be empty.');
        }

        if (mb_strlen($customer) > 255) {
            throw new InvalidArgumentException('customer must not exceed 255 characters.');
        }

        return $customer;
    }

    /**
     * A sku is a catalog key, not free text: it is the identity three later
     * loads are made with, so an unusable one must be refused at the edge
     * rather than become three empty reads. An absent sku is not an error -
     * it is the default product.
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
     */
    private function normalizeAmount(mixed $value): string
    {
        if (is_int($value)) {
            $value = sprintf('%.2F', $value);
        } elseif (is_float($value)) {
            $value = sprintf('%.2F', $value);
        }

        if (!is_string($value) || preg_match('/^[+-]?\d+(?:\.\d+)?$/', $value) !== 1) {
            throw new InvalidArgumentException('amount must be a number.');
        }

        if (str_starts_with($value, '-')) {
            throw new InvalidArgumentException('amount must not be negative.');
        }

        $parts = explode('.', $value);
        $integer = ltrim($parts[0], '0') === '' ? '0' : ltrim($parts[0], '0');

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
