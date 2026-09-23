<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Domain;

use InvalidArgumentException;
use PhpSystemsPlatform\Storage\Repositories\OrderRepository;
use Ramsey\Uuid\Uuid;

/**
 * The whole synchronous order lifecycle, on top of the repository: validates
 * the domain invariants (what a customer and an amount are), generates the
 * UUID v7 id, books the timestamps, and lets the handler stay a thin HTTP
 * translation layer. The queue phase turns this into the write → enqueue →
 * respond path by adding a Publisher seam here without touching the handlers.
 */
final readonly class OrderService
{
    public function __construct(
        private OrderRepository $orders,
    ) {
    }

    public function createOrder(string $customer, mixed $amount): Order
    {
        $customer = $this->normalizeCustomer($customer);
        $amount = $this->normalizeAmount($amount);

        $now = $this->now();

        $order = new Order(
            id: Uuid::uuid7()->toString(),
            customer: $customer,
            amount: $amount,
            status: OrderStatus::CREATED,
            createdAt: $now,
            updatedAt: $now,
        );

        if (!$this->orders->create($order)) {
            throw new \RuntimeException('Could not persist the order.');
        }

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
